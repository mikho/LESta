<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AccountBackupDownload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Receives a decrypted account backup from its node, in chunks, at a one-time address (see
 * PrepareAccountBackupDownload). The token alone authorizes it, so an unknown, used or expired token
 * is a plain 404. Each chunk must arrive at exactly the size received so far, so a retry or a replay
 * cannot corrupt the file; the empty chunk marked final completes it. The node sends the final marker
 * only after the whole sealed backup authenticated.
 */
class AgentAccountBackupUploadController extends Controller
{
    /** The largest single chunk accepted (the node sends 4 MB). */
    private const int MAX_CHUNK_BYTES = 5 * 1024 * 1024;

    public function store(Request $request, string $token): Response
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            abort(404);
        }

        $download = AccountBackupDownload::query()
            ->where('token_hash', AccountBackupDownload::hashToken($token))
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        abort_if($download === null, 404);

        $chunk = $request->getContent();

        if (strlen($chunk) > self::MAX_CHUNK_BYTES) {
            abort(413, 'The chunk is too large.');
        }

        $path = $download->path ?? 'account-backup-downloads/'.$download->uuid.'.tar.gz';
        $disk = Storage::disk('local');

        $received = $disk->exists($path) ? $disk->size($path) : 0;

        if ((int) $request->query('offset', -1) !== $received) {
            abort(409, 'Expected the next chunk at offset '.$received.'.');
        }

        if ($received + strlen($chunk) > AccountBackupDownload::MAX_BYTES) {
            $this->fail($download, $path, __('The backup is larger than the download limit.'));

            abort(413, 'The backup is too large.');
        }

        if ($chunk !== '') {
            $disk->makeDirectory('account-backup-downloads');
            file_put_contents($disk->path($path), $chunk, FILE_APPEND | LOCK_EX);
        }

        $download->forceFill(['path' => $path, 'size_bytes' => $received + strlen($chunk)]);

        if ($request->boolean('final')) {
            $download->forceFill(['status' => 'ready', 'expires_at' => now()->addMinutes(AccountBackupDownload::READY_MINUTES)]);
        }

        $download->save();

        return response()->noContent();
    }

    private function fail(AccountBackupDownload $download, string $path, string $message): void
    {
        Storage::disk('local')->delete($path);
        $download->forceFill(['status' => 'failed', 'error_message' => $message, 'path' => null, 'size_bytes' => 0])->save();
    }
}
