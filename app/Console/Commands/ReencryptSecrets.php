<?php

namespace App\Console\Commands;

use App\Models\AccountBackup;
use App\Models\AccountBackupDestination;
use App\Models\AcmeAccount;
use App\Models\Backup;
use App\Models\MailAccount;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Re-encrypts every secret stored in the database with the current APP_KEY, the second half of a key
 * rotation. Rotating: put the old key in APP_PREVIOUS_KEYS and a new one in APP_KEY, run this, then
 * drop the old key. Values written with a previous key still decrypt in the meantime, so nothing
 * breaks while it runs, and running it again is harmless.
 *
 * Each value is decrypted and encrypted as a string and written straight to its column, so no
 * timestamps change and no model events fire. This covers the `encrypted` casts of the models below
 * and the two-factor columns Fortify encrypts itself.
 */
class ReencryptSecrets extends Command
{
    /**
     * The models whose `encrypted` casts hold secrets. A test checks that no model with such a cast is
     * missing from this list.
     *
     * @var list<class-string<Model>>
     */
    public const MODELS = [
        AccountBackup::class,
        AccountBackupDestination::class,
        AcmeAccount::class,
        Backup::class,
        MailAccount::class,
        ProvisioningOperation::class,
        TenantDatabase::class,
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lesta:reencrypt-secrets';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-encrypt every stored secret with the current APP_KEY (after moving the old key to APP_PREVIOUS_KEYS).';

    public function handle(): int
    {
        // Fortify derives passkey user handles from APP_KEY unless a secret of its own is set, so a
        // new APP_KEY would quietly stop every passkey from signing in.
        if (config('app.previous_keys') !== [] && config('fortify.passkeys.user_handle_secret') === config('app.key')) {
            $this->error('Set PASSKEYS_USER_HANDLE_SECRET to the previous APP_KEY first: passkey sign-in depends on it.');

            return self::FAILURE;
        }

        $unreadable = 0;

        foreach ($this->columns() as $table => $columns) {
            [$rewritten, $failed] = $this->reencryptTable($table, $columns);
            $unreadable += $failed;

            $this->line(sprintf('%s: %d value(s) re-encrypted%s', $table, $rewritten, $failed > 0 ? ", {$failed} unreadable with any key" : ''));
        }

        if ($unreadable > 0) {
            $this->error("{$unreadable} value(s) could not be decrypted with APP_KEY or APP_PREVIOUS_KEYS and were left as they are. Keep the old key until they are dealt with.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, list<string>> table name => encrypted columns
     */
    private function columns(): array
    {
        $columns = ['users' => ['two_factor_secret', 'two_factor_recovery_codes']];

        foreach (self::MODELS as $class) {
            $model = new $class;
            $encrypted = array_keys(array_filter($model->getCasts(), fn (mixed $cast): bool => is_string($cast) && str_starts_with($cast, 'encrypted')));

            $columns[$model->getTable()] = $encrypted;
        }

        return $columns;
    }

    /**
     * @param  list<string>  $columns
     * @return array{0: int, 1: int} values rewritten, values no key could decrypt
     */
    private function reencryptTable(string $table, array $columns): array
    {
        $rewritten = 0;
        $failed = 0;

        DB::table($table)->select(['id', ...$columns])->orderBy('id')->chunkById(500, function ($rows) use ($table, $columns, &$rewritten, &$failed): void {
            foreach ($rows as $row) {
                $changes = [];

                foreach ($columns as $column) {
                    if ($row->{$column} === null) {
                        continue;
                    }

                    try {
                        $changes[$column] = Crypt::encryptString(Crypt::decryptString($row->{$column}));
                    } catch (DecryptException) {
                        $failed++;
                    }
                }

                if ($changes !== []) {
                    DB::table($table)->where('id', $row->id)->update($changes);
                    $rewritten += count($changes);
                }
            }
        });

        return [$rewritten, $failed];
    }
}
