<?php

namespace App\Http\Controllers\Backups;

use App\Actions\AccountBackups\CopyAccountBackupOffNode;
use App\Actions\AccountBackups\CreateAccountBackup;
use App\Actions\AccountBackups\DeleteAccountBackup;
use App\Actions\AccountBackups\ResolvesAccountBackupScope;
use App\Actions\AccountBackups\RestoreAccountBackup;
use App\Concerns\ResolvesCurrentAccount;
use App\Exceptions\NoBackupCapableNodeAvailableException;
use App\Http\Controllers\Controller;
use App\Models\AccountBackup;
use App\Models\AccountBackupDestination;
use App\Models\AccountBackupSchedule;
use App\Models\Node;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An account's own backups: back up now, restore chosen parts, delete. See
 * App\Actions\AccountBackups for what each does on the node.
 */
class AccountBackupController extends Controller
{
    use ResolvesCurrentAccount;

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
            'status' => $backup->state(),
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
