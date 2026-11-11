<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Receives a decrypted whole-node backup from its node, in chunks, at a one-time address (see
 * PrepareBackupDownload). The token alone authorizes it, so an unknown, used or expired token is a
 * plain 404. Each chunk must arrive at exactly the size received so far, so a retry or a replay cannot
 * corrupt the file; the empty chunk marked final completes it, and only then can it be downloaded. The
 * node sends the final marker only after the whole sealed backup authenticated.
 */
class AgentBackupUploadController extends Controller
{
    /** The largest single chunk accepted (the node sends 4 MB). */
    private const int MAX_CHUNK_BYTES = 5 * 1024 * 1024;

    public function store(Request $request): Response
    {
        // The token is in the Authorization header, not the address, so it is not written to access logs.
        $token = (string) $request->bearerToken();

        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            abort(404);
        }

        // One chunk at a time per backup: with the row locked, two chunks sent at once cannot both pass
        // the offset check and append twice.
        $tooLarge = DB::transaction(function () use ($request, $token): bool {
            $backup = Backup::query()
                ->where('download_token_hash', Backup::hashDownloadToken($token))
                ->whereNull('download_ready_at')
                ->where('download_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            abort_if($backup === null, 404);

            $chunk = $request->getContent();

            if (strlen($chunk) > self::MAX_CHUNK_BYTES) {
                abort(413, 'The chunk is too large.');
            }

            $path = $backup->download_path ?? 'backup-downloads/'.$backup->uuid.'.tar.gz';
            $disk = Storage::disk('local');

            $received = $disk->exists($path) ? $disk->size($path) : 0;

            if ((int) $request->query('offset', -1) !== $received) {
                abort(409, 'Expected the next chunk at offset '.$received.'.');
            }

            if ($received + strlen($chunk) > Backup::MAX_DOWNLOAD_BYTES) {
                // Answered outside the transaction, so the cleanup is kept.
                $this->discard($backup, $path);

                return true;
            }

            if ($chunk !== '') {
                $disk->makeDirectory('backup-downloads');
                file_put_contents($disk->path($path), $chunk, FILE_APPEND | LOCK_EX);
            }

            $backup->forceFill(['download_path' => $path]);

            if ($request->boolean('final')) {
                $backup->forceFill([
                    'download_ready_at' => now(),
                    'download_expires_at' => now()->addMinutes(Backup::READY_MINUTES),
                    'download_token_hash' => null,
                ]);
            }

            $backup->save();

            return false;
        });

        abort_if($tooLarge, 413, 'The backup is too large.');

        return response()->noContent();
    }

    private function discard(Backup $backup, string $path): void
    {
        Storage::disk('local')->delete($path);

        $backup->forceFill([
            'download_path' => null,
            'download_ready_at' => null,
            'download_expires_at' => null,
            'download_token_hash' => null,
        ])->save();
    }
}
