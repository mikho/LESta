<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AccountBackupImport;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands an uploaded account backup to its node (see ImportAccountBackup). The token alone
 * authorizes it, so an unknown, expired or not yet complete upload is a plain 404. The file is
 * removed when the node reports the import done (see RecordsAccountBackupResult).
 */
class AgentAccountBackupImportController extends Controller
{
    public function show(string $token): StreamedResponse
    {
        abort_if(preg_match('/^[0-9a-f]{64}$/', $token) !== 1, 404);

        $import = AccountBackupImport::query()
            ->where('token_hash', AccountBackupImport::hashToken($token))
            ->where('status', 'ready')
            ->where('expires_at', '>', now())
            ->first();

        abort_if($import === null || $import->path === null || ! Storage::disk('local')->exists($import->path), 404);

        return Storage::disk('local')->response($import->path, null, ['Content-Type' => 'application/gzip']);
    }
}
