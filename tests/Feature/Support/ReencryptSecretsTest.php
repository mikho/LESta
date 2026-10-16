<?php

use App\Console\Commands\ReencryptSecrets;
use App\Models\TenantDatabase;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Switches the application to a new key with the old one kept as a previous key, as an operator does
 * before running the command.
 */
function rotateToNewKey(string $passkeySecret = 'old'): string
{
    $oldKey = config('app.key');
    $newKey = 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC'));

    config([
        'app.key' => $newKey,
        'app.previous_keys' => [$oldKey],
        'fortify.passkeys.user_handle_secret' => $passkeySecret === 'old' ? $oldKey : $newKey,
    ]);

    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');

    return $newKey;
}

function encrypterFor(string $key): Encrypter
{
    return new Encrypter(base64_decode(substr($key, 7)), 'AES-256-CBC');
}

test('every stored secret is re-encrypted with the new key alone', function () {
    $database = TenantDatabase::factory()->create(['password' => 'tenant-db-password']);
    $user = User::factory()->create(['two_factor_secret' => encrypt('TOTPSECRET')]);
    $updatedAt = DB::table('tenant_databases')->where('id', $database->id)->value('updated_at');

    $newKey = rotateToNewKey();

    $this->artisan('lesta:reencrypt-secrets')->assertSuccessful();

    $onlyNew = encrypterFor($newKey);
    $raw = DB::table('tenant_databases')->where('id', $database->id)->first();

    expect($onlyNew->decryptString($raw->password))->toBe('tenant-db-password')
        ->and($onlyNew->decrypt(DB::table('users')->where('id', $user->id)->value('two_factor_secret')))->toBe('TOTPSECRET')
        ->and($raw->updated_at)->toBe($updatedAt)
        ->and($database->fresh()->password)->toBe('tenant-db-password');
});

test('it refuses while passkey sign-in still depends on the new key', function () {
    TenantDatabase::factory()->create(['password' => 'tenant-db-password']);
    $before = DB::table('tenant_databases')->value('password');

    rotateToNewKey(passkeySecret: 'new');

    $this->artisan('lesta:reencrypt-secrets')->assertFailed();

    expect(DB::table('tenant_databases')->value('password'))->toBe($before);
});

test('a value no key can decrypt is reported and left as it is', function () {
    $database = TenantDatabase::factory()->create();
    $foreign = encrypterFor('base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC')))->encryptString('lost');
    DB::table('tenant_databases')->where('id', $database->id)->update(['password' => $foreign]);

    rotateToNewKey();

    $this->artisan('lesta:reencrypt-secrets')->assertFailed();

    expect(DB::table('tenant_databases')->where('id', $database->id)->value('password'))->toBe($foreign);
});

test('every model with an encrypted cast is covered', function () {
    $withEncryptedCasts = collect(glob(app_path('Models/*.php')))
        ->map(fn (string $path): string => 'App\\Models\\'.basename($path, '.php'))
        ->filter(fn (string $class): bool => is_subclass_of($class, Model::class))
        ->filter(fn (string $class): bool => collect((new $class)->getCasts())->contains(fn (mixed $cast): bool => is_string($cast) && str_starts_with($cast, 'encrypted')))
        ->values()
        ->all();

    expect(array_diff($withEncryptedCasts, ReencryptSecrets::MODELS))->toBe([]);
});
