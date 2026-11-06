<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\NodeAgentUpgrade;
use App\Services\AgentUpgrade\AgentRelease;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Hands the agent binary to the node an administrator signed an upgrade for (see UpgradeNodeAgent).
 * The one-time token in the Authorization header (so it stays out of access logs) is the only
 * credential, and it works while the signed request is valid and the binary is still the one that
 * was signed for. The node verifies the signature and the sha256 itself before installing it.
 */
class AgentBinaryController extends Controller
{
    public function show(Request $request): BinaryFileResponse
    {
        $token = (string) $request->bearerToken();

        abort_if(preg_match('/^[0-9a-f]{64}$/', $token) !== 1, 404);

        $upgrade = NodeAgentUpgrade::query()
            ->where('token_hash', NodeAgentUpgrade::hashToken($token))
            ->where('expires_at', '>', now())
            ->first();

        $release = AgentRelease::current();

        abort_if($upgrade === null || $release === null || $release->sha256 !== $upgrade->sha256, 404);

        $upgrade->forceFill(['downloaded_at' => now()])->save();

        return response()->file($release->path, ['Content-Type' => 'application/octet-stream']);
    }
}
