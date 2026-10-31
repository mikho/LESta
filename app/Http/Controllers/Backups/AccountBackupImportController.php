<?php

namespace App\Http\Controllers\Backups;

use App\Actions\AccountBackups\ChecksStagingSpace;
use App\Actions\AccountBackups\CreateAccountBackup;
use App\Actions\AccountBackups\ImportAccountBackup;
use App\Actions\AccountBackups\ResolvesAccountBackupScope;
use App\Actions\Provisioning\ResolvesBackupCapableNode;
use App\Concerns\ResolvesCurrentAccount;
use App\Exceptions\NoBackupCapableNodeAvailableException;
use App\Http\Controllers\Controller;
use App\Models\AccountBackup;
use App\Models\AccountBackupImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Bringing a backup file back (see ImportAccountBackup): from the owner's own storage, or uploaded
 * from their computer in chunks.
 */
class AccountBackupImportController extends Controller
{
    use ResolvesCurrentAccount;

    /** The same character set the node accepts for an object name. */
    private const string OBJECT_KEY_PATTERN = "/^[A-Za-z0-9!_.*'()\/-]+$/";

    /** The largest chunk of an uploaded backup accepted in one request. */
    private const int MAX_CHUNK_BYTES = 5 * 1024 * 1024;

    /**
     * Brings a backup file from the owner's own storage back as a backup of this account.
     */
    public function fromStorage(Request $request): RedirectResponse
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
    public function begin(Request $request): JsonResponse
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

        // One uploaded file waits for its node at a time per account, and only while the disk
        // could hold it: any owner can start an upload, and each can be 4 GB.
        $uploadInProgress = AccountBackupImport::query()
            ->where('account_id', $account->id)
            ->whereIn('status', ['uploading', 'ready'])
            ->where('expires_at', '>', now())
            ->exists();

        if ($uploadInProgress) {
            throw ValidationException::withMessages(['backup' => __('An upload for this account is already in progress. Wait for it to finish.')]);
        }

        if (! app(ChecksStagingSpace::class)->hasRoomFor(AccountBackupImport::MAX_BYTES)) {
            throw ValidationException::withMessages(['backup' => __('The server has no room for an upload right now. Try again later.')]);
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
    public function chunk(Request $request, AccountBackupImport $import): JsonResponse
    {
        $account = $this->resolveAccount($request->user());

        abort_if($account === null || $import->account_id !== $account->id || $import->status !== 'uploading' || $import->expires_at->isPast(), 404);

        Gate::authorize('create', [AccountBackup::class, $account]);

        // One chunk at a time per upload: with the row locked, two chunks sent at once cannot both
        // pass the offset check and append twice. Every answer is returned, never thrown, so what
        // failUpload records is kept. The import itself starts after the commit.
        $outcome = DB::transaction(function () use ($request, $import): JsonResponse|string {
            $import = AccountBackupImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();

            if ($import->status !== 'uploading') {
                return response()->json(['message' => __('The upload is out of step. Start it again.'), 'received' => $import->size_bytes], 409);
            }

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

            return $token;
        });

        if ($outcome instanceof JsonResponse) {
            return $outcome;
        }

        $import->refresh();

        try {
            app(ImportAccountBackup::class)->fromUpload($request->user(), $import, $outcome);
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
}
