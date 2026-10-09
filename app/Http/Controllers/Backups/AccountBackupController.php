<?php

namespace App\Http\Controllers\Backups;

use App\Actions\AccountBackups\CopyAccountBackupOffNode;
use App\Actions\AccountBackups\CreateAccountBackup;
use App\Actions\AccountBackups\DeleteAccountBackup;
use App\Actions\AccountBackups\ImportAccountBackup;
use App\Actions\AccountBackups\PrepareAccountBackupDownload;
use App\Actions\AccountBackups\ResolvesAccountBackupScope;
use App\Actions\AccountBackups\RestoreAccountBackup;
use App\Actions\Provisioning\ResolvesBackupCapableNode;
use App\Concerns\ResolvesCurrentAccount;
use App\Enums\ProvisioningStatus;
use App\Exceptions\NoBackupCapableNodeAvailableException;
use App\Http\Controllers\Controller;
use App\Models\AccountBackup;
use App\Models\AccountBackupDestination;
use App\Models\AccountBackupDownload;
use App\Models\AccountBackupImport;
use App\Models\AccountBackupSchedule;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An account's own backups: back up now, restore chosen parts, delete. See
 * App\Actions\AccountBackups for what each does on the node.
 */
class AccountBackupController extends Controller
{
    use ResolvesCurrentAccount;

    /** The same character set the node accepts for an object name. */
    private const string OBJECT_KEY_PATTERN = "/^[A-Za-z0-9!_.*'()\/-]+$/";

    /** The largest chunk of an uploaded backup accepted in one request. */
    private const int MAX_CHUNK_BYTES = 5 * 1024 * 1024;

    public function index(Request $request): Response
    {
        $account = $this->resolveAccount($request->user());

        if ($account === null) {
            return Inertia::render('account-backups/index', ['backups' => null, 'nodes' => [], 'keep' => AccountBackup::KEEP_BY_KIND, 'schedule' => null, 'destination' => null]);
        }

        Gate::authorize('viewAny', [AccountBackup::class, $account]);

        $resolver = app(ResolvesAccountBackupScope::class);

        return Inertia::render('account-backups/index', [
            'backups' => $account->hasMany(AccountBackup::class)->with(['node:id,uuid,name', 'downloads'])->latest('id')->get()->map(fn (AccountBackup $backup): array => $this->present($backup))->all(),
            'nodes' => array_map(function (Node $node) use ($account, $resolver): array {
                $scope = $resolver->for($account, $node);

                return [
                    'uuid' => $node->uuid,
                    'name' => $node->name,
                    'parts' => $resolver->availableParts($scope),
                    'counts' => ['files' => count($scope['web_resources']), 'databases' => count($scope['databases']), 'mail' => count($scope['mail_domains'])],
                    'can_back_up' => $node->capabilities()->where('capability', 'backup.encrypted-artifacts.v1')->whereNull('suspended_at')->exists(),
                ];
            }, $resolver->nodesWithData($account)),
            'keep' => AccountBackup::KEEP_BY_KIND,
            'schedule' => $this->presentSchedule($account->backupSchedule),
            'destination' => $this->presentDestination($account->backupDestination),
            'can_manage' => $request->user()->can('create', [AccountBackup::class, $account]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->resolveAccount($request->user());

        abort_if($account === null, 404);

        Gate::authorize('create', [AccountBackup::class, $account]);

        $data = $request->validate([
            'node' => ['required', 'string'],
            'parts' => ['required', 'array', 'min:1'],
            'parts.*' => ['string', Rule::in(AccountBackup::PARTS)],
        ], ['parts.required' => __('Choose what to back up.'), 'parts.min' => __('Choose what to back up.')]);

        $node = collect(app(ResolvesAccountBackupScope::class)->nodesWithData($account))->firstWhere('uuid', $data['node']);

        abort_if($node === null, 404);

        try {
            app(CreateAccountBackup::class)->handle($request->user(), $account, $node, $data['parts']);
        } catch (NoBackupCapableNodeAvailableException) {
            throw ValidationException::withMessages(['backup' => __('Backups are not available on :node.', ['node' => $node->name])]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backup started. It appears below when it is ready.')]);

        return to_route('account-backups.index');
    }

    /**
     * Brings a backup file from the owner's own storage back as a backup of this account.
     */
    public function importFromStorage(Request $request): RedirectResponse
    {
        $account = $this->resolveAccount($request->user());

        abort_if($account === null, 404);

        Gate::authorize('create', [AccountBackup::class, $account]);

        $data = $request->validate([
            'node' => ['required', 'string'],
            'object_key' => [
                'required', 'string', 'max:512', 'regex:'.self::OBJECT_KEY_PATTERN,
                fn (string $a, mixed $v, \Closure $fail) => str_contains((string) $v, '..') || str_contains((string) $v, '//') || str_starts_with((string) $v, '/') ? $fail(__('The file name cannot contain .. or // or start with /.')) : null,
            ],
            'label' => ['nullable', 'string', 'max:120'],
        ], ['object_key.regex' => __('The file name can use letters, numbers and ! _ . * \' ( ) / - only.')]);

        $node = collect(app(ResolvesAccountBackupScope::class)->nodesWithData($account))->firstWhere('uuid', $data['node']);

        abort_if($node === null, 404);

        try {
            app(ImportAccountBackup::class)->fromStorage($request->user(), $account, $node, $data['object_key'], $data['label'] ?? null);
        } catch (NoBackupCapableNodeAvailableException) {
            throw ValidationException::withMessages(['backup' => __('Backups are not available on :node.', ['node' => $node->name])]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Importing the backup from your storage. It appears below when it is ready.')]);

        return to_route('account-backups.index');
    }

    /**
     * Opens an upload of a backup file from the owner's computer. The page then sends the file with
     * uploadChunk(). Checked up front so a large file is not sent to a node that cannot take it.
     */
    public function beginUpload(Request $request): JsonResponse
    {
        $account = $this->resolveAccount($request->user());

        abort_if($account === null, 404);

        Gate::authorize('create', [AccountBackup::class, $account]);

        $data = $request->validate([
            'node' => ['required', 'string'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $node = collect(app(ResolvesAccountBackupScope::class)->nodesWithData($account))->firstWhere('uuid', $data['node']);

        abort_if($node === null, 404);

        try {
            app(ResolvesBackupCapableNode::class)->resolveFor($node);
        } catch (NoBackupCapableNodeAvailableException) {
            throw ValidationException::withMessages(['backup' => __('Backups are not available on :node.', ['node' => $node->name])]);
        }

        if (app(CreateAccountBackup::class)->isBusy($account, $node)) {
            throw ValidationException::withMessages(['backup' => __('A backup or restore is already running for this account on :node.', ['node' => $node->name])]);
        }

        $import = AccountBackupImport::query()->create([
            'account_id' => $account->id,
            'node_id' => $node->id,
            'label' => $data['label'] ?? null,
            'status' => 'uploading',
            'size_bytes' => 0,
            'expires_at' => now()->addHours(AccountBackupImport::KEEP_HOURS),
        ]);

        return response()->json([
            'chunk_url' => route('account-backups.import.chunk', $import),
            'max_bytes' => AccountBackupImport::MAX_BYTES,
        ]);
    }

    /**
     * Receives one chunk of an uploaded backup file. A chunk must arrive at exactly the size
     * received so far, so a retry cannot corrupt the file; the request marked final completes it and
     * starts the import.
     */
    public function uploadChunk(Request $request, AccountBackupImport $import): JsonResponse
    {
        $account = $this->resolveAccount($request->user());

        abort_if($account === null || $import->account_id !== $account->id || $import->status !== 'uploading' || $import->expires_at->isPast(), 404);

        Gate::authorize('create', [AccountBackup::class, $account]);

        $chunk = $request->getContent();

        if (strlen($chunk) > self::MAX_CHUNK_BYTES) {
            return response()->json(['message' => __('The chunk is too large.')], 413);
        }

        $path = $import->path ?? 'account-backup-imports/'.$import->uuid.'.tar.gz';
        $disk = Storage::disk('local');
        $received = $disk->exists($path) ? $disk->size($path) : 0;

        if ((int) $request->query('offset', -1) !== $received) {
            return response()->json(['message' => __('The upload is out of step. Start it again.'), 'received' => $received], 409);
        }

        if ($received === 0 && $chunk !== '' && ! str_starts_with($chunk, "\x1f\x8b")) {
            return $this->failUpload($import, __('That is not a backup file. Choose the .tar.gz file you downloaded or copied.'));
        }

        if ($received + strlen($chunk) > AccountBackupImport::MAX_BYTES) {
            return $this->failUpload($import, __('That file is larger than the 4 GB import limit.'));
        }

        if ($chunk !== '') {
            $disk->makeDirectory('account-backup-imports');
            file_put_contents($disk->path($path), $chunk, FILE_APPEND | LOCK_EX);
        }

        $import->forceFill(['path' => $path, 'size_bytes' => $received + strlen($chunk)]);

        if (! $request->boolean('final')) {
            $import->save();

            return response()->json(['received' => $import->size_bytes]);
        }

        if ($import->size_bytes === 0) {
            return $this->failUpload($import, __('The file is empty.'));
        }

        $token = bin2hex(random_bytes(32));

        $import->forceFill(['status' => 'ready', 'token_hash' => AccountBackupImport::hashToken($token), 'expires_at' => now()->addHours(AccountBackupImport::KEEP_HOURS)])->save();

        try {
            app(ImportAccountBackup::class)->fromUpload($request->user(), $import, $token);
        } catch (NoBackupCapableNodeAvailableException|ValidationException $e) {
            $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : __('Backups are not available on :node.', ['node' => $import->node->name]);

            return $this->failUpload($import, (string) $message);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Importing the backup. It appears below when it is ready.')]);

        return response()->json(['status' => 'started']);
    }

    private function failUpload(AccountBackupImport $import, string $message): JsonResponse
    {
        if ($import->path !== null) {
            Storage::disk('local')->delete($import->path);
        }

        $import->delete();

        return response()->json(['message' => $message], 422);
    }

    /**
     * Sets the account's automatic backup schedule: off, daily or weekly, and what to include.
     */
    public function updateSchedule(Request $request): RedirectResponse
    {
        $account = $this->resolveAccount($request->user());

        abort_if($account === null, 404);

        Gate::authorize('create', [AccountBackup::class, $account]);

        $data = $request->validate([
            'frequency' => ['required', Rule::in(AccountBackupSchedule::FREQUENCIES)],
            'parts' => ['required_unless:frequency,off', 'array'],
            'parts.*' => ['string', Rule::in(AccountBackup::PARTS)],
        ], ['parts.required_unless' => __('Choose what to back up.')]);

        $schedule = $account->backupSchedule ?? new AccountBackupSchedule(['account_id' => $account->id, 'parts' => AccountBackup::PARTS]);

        $parts = array_values(array_intersect(AccountBackup::PARTS, $data['parts'] ?? $schedule->parts));

        $schedule->forceFill(['account_id' => $account->id, 'frequency' => $data['frequency'], 'parts' => $parts === [] ? AccountBackup::PARTS : $parts]);
        $schedule->forceFill(['next_run_at' => $data['frequency'] === 'off' ? null : $schedule->nextRunAfter(now())])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $data['frequency'] === 'off' ? __('Automatic backups are off.') : __('Automatic backups saved.')]);

        return to_route('account-backups.index');
    }

    /**
     * Saves the account's own S3-compatible storage. The keys are write-only: left empty on a later
     * save they keep their saved values.
     */
    public function updateDestination(Request $request): RedirectResponse
    {
        $account = $this->resolveAccount($request->user());

        abort_if($account === null, 404);

        Gate::authorize('create', [AccountBackup::class, $account]);

        $existing = $account->backupDestination;

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'endpoint' => [
                'required', 'string', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $parts = parse_url((string) $value);

                    if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
                        $fail(__('Use an https address of the storage service, such as https://s3.eu-west-1.amazonaws.com.'));
                    }
                },
            ],
            'region' => ['required', 'string', 'regex:'.AccountBackupDestination::REGION_PATTERN],
            'bucket' => ['required', 'string', 'regex:'.AccountBackupDestination::BUCKET_PATTERN],
            'prefix' => ['nullable', 'string', 'regex:'.AccountBackupDestination::PREFIX_PATTERN, fn (string $a, mixed $v, \Closure $fail) => str_contains((string) $v, '..') || str_contains((string) $v, '//') ? $fail(__('The folder cannot contain .. or //.')) : null],
            'access_key' => [$existing === null ? 'required' : 'nullable', 'string', 'regex:'.AccountBackupDestination::KEY_PATTERN],
            'secret_key' => [$existing === null ? 'required' : 'nullable', 'string', 'regex:'.AccountBackupDestination::SECRET_PATTERN],
        ], [
            'region.regex' => __('The region is lower-case letters, numbers and dashes, such as eu-west-1.'),
            'bucket.regex' => __('The bucket name is not valid.'),
            'prefix.regex' => __('The folder can use letters, numbers and . _ - / only.'),
            'access_key.regex' => __('The access key is not valid.'),
            'secret_key.regex' => __('The secret key is not valid.'),
        ]);

        $attributes = [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'endpoint' => rtrim($data['endpoint'], '/'),
            'region' => $data['region'],
            'bucket' => $data['bucket'],
            'prefix' => $data['prefix'] ?? '',
        ];

        if (($data['access_key'] ?? '') !== '') {
            $attributes['access_key'] = $data['access_key'];
        }

        if (($data['secret_key'] ?? '') !== '') {
            $attributes['secret_key'] = $data['secret_key'];
        }

        if ($existing === null) {
            $account->backupDestination()->create($attributes);
        } else {
            $existing->update($attributes);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your storage is saved.')]);

        return to_route('account-backups.index');
    }

    public function destroyDestination(Request $request): RedirectResponse
    {
        $account = $this->resolveAccount($request->user());

        abort_if($account === null, 404);

        Gate::authorize('create', [AccountBackup::class, $account]);

        $account->backupDestination()->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your storage is removed. Copies already made stay where they are.')]);

        return to_route('account-backups.index');
    }

    /**
     * Copies a finished backup to the account's storage now (a retry, or an older backup).
     */
    public function copy(Request $request, AccountBackup $backup): RedirectResponse
    {
        try {
            app(CopyAccountBackupOffNode::class)->handle($request->user(), $backup);
        } catch (NoBackupCapableNodeAvailableException) {
            throw ValidationException::withMessages(['backup' => __('Backups are not available on :node.', ['node' => $backup->node->name])]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Copying to your storage.')]);

        return to_route('account-backups.index');
    }

    public function restore(Request $request, AccountBackup $backup): RedirectResponse
    {
        Gate::authorize('restore', $backup);

        $data = $request->validate([
            'parts' => ['required', 'array', 'min:1'],
            'parts.*' => ['string', Rule::in(AccountBackup::PARTS)],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => __('Confirm that you understand the restore overwrites current data.'), 'parts.required' => __('Choose what to restore.'), 'parts.min' => __('Choose what to restore.')]);

        try {
            app(RestoreAccountBackup::class)->handle($request->user(), $backup, $data['parts']);
        } catch (NoBackupCapableNodeAvailableException) {
            throw ValidationException::withMessages(['backup' => __('Backups are not available on :node.', ['node' => $backup->node->name])]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Restore started. A backup of the current data is taken first.')]);

        return to_route('account-backups.index');
    }

    /**
     * Asks the node to prepare a downloadable copy; the page shows it when it is ready.
     */
    public function prepareDownload(Request $request, AccountBackup $backup): RedirectResponse
    {
        try {
            app(PrepareAccountBackupDownload::class)->handle($request->user(), $backup);
        } catch (NoBackupCapableNodeAvailableException) {
            throw ValidationException::withMessages(['backup' => __('Backups are not available on :node.', ['node' => $backup->node->name])]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Preparing the download. It appears here in a moment.')]);

        return to_route('account-backups.index');
    }

    /**
     * Streams a ready copy as a tar.gz. It stays available until it expires.
     */
    public function download(AccountBackup $backup, AccountBackupDownload $download): StreamedResponse
    {
        Gate::authorize('download', $backup);

        abort_unless($download->account_backup_id === $backup->id && $download->isReady() && $download->path !== null && Storage::disk('local')->exists($download->path), 404);

        $name = Str::slug($backup->account->name).'-backup-'.($backup->completed_at ?? $backup->created_at)?->format('Y-m-d-Hi').'.tar.gz';

        return Storage::disk('local')->download($download->path, $name, ['Content-Type' => 'application/gzip']);
    }

    public function destroy(Request $request, AccountBackup $backup): RedirectResponse
    {
        app(DeleteAccountBackup::class)->handle($request->user(), $backup);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backup deleted.')]);

        return to_route('account-backups.index');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentDownload(AccountBackup $backup): ?array
    {
        $download = $backup->downloads->sortByDesc('id')->first();

        if ($download === null || ($download->status !== 'failed' && $download->expires_at->isPast())) {
            return null;
        }

        return [
            'status' => $download->status,
            'size_bytes' => $download->size_bytes,
            'expires_at' => $download->expires_at->toIso8601String(),
            'error' => $download->error_message,
            'url' => $download->isReady() ? route('account-backups.download', [$backup, $download]) : null,
        ];
    }

    /**
     * The saved storage without its keys, which are never sent back.
     *
     * @return array<string, mixed>|null
     */
    private function presentDestination(?AccountBackupDestination $destination): ?array
    {
        if ($destination === null) {
            return null;
        }

        return [
            'enabled' => $destination->enabled,
            'endpoint' => $destination->endpoint,
            'region' => $destination->region,
            'bucket' => $destination->bucket,
            'prefix' => $destination->prefix,
            'has_keys' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSchedule(?AccountBackupSchedule $schedule): array
    {
        if ($schedule === null) {
            return ['frequency' => 'off', 'parts' => AccountBackup::PARTS, 'next_run_at' => null, 'last_run_at' => null, 'last_message' => null];
        }

        return [
            'frequency' => $schedule->frequency,
            'parts' => $schedule->parts,
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            'last_run_at' => $schedule->last_run_at?->toIso8601String(),
            'last_message' => $schedule->last_message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AccountBackup $backup): array
    {
        return [
            'uuid' => $backup->uuid,
            'label' => $backup->label,
            'kind' => $backup->kind,
            'node' => $backup->node->name,
            'status' => in_array($backup->status, [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied], true) ? 'ready' : ($backup->status === ProvisioningStatus::Failed || $backup->status === ProvisioningStatus::Rejected || $backup->status === ProvisioningStatus::Degraded ? 'failed' : 'running'),
            'error' => $backup->error_message,
            'parts' => $backup->parts ?? $backup->requested_parts,
            'size_bytes' => $backup->size_bytes,
            'download' => $this->presentDownload($backup),
            'remote' => $backup->remote_status === null ? null : [
                'status' => $backup->remote_status,
                'key' => $backup->remote_key,
                'at' => $backup->remote_at?->toIso8601String(),
                'error' => $backup->remote_error,
            ],
            'skipped' => $backup->report['skipped'] ?? [],
            'created_at' => $backup->created_at?->toIso8601String(),
            'restore' => $backup->last_restore_status === null ? null : [
                'status' => $backup->last_restore_status,
                'at' => $backup->last_restore_at?->toIso8601String(),
                'restored' => $backup->last_restore_report['restored'] ?? [],
                'skipped' => $backup->last_restore_report['skipped'] ?? [],
                'error' => $backup->last_restore_error,
            ],
        ];
    }
}
