<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\TenantDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * This is a live, synchronous, node-initiated HTTPS read of an already-provisioned resource's
 * own credentials -- never routed through ProvisioningOperation/OperationEnvelope, since nothing
 * is being mutated here (the one mutation this whole flow makes, marking the token used, already
 * happened in App\Http\Middleware\AuthenticateAdminerToken before this controller ever runs).
 * It is also the *reverse direction* of this codebase's usual "Laravel never executes host-level
 * mutations on a node": here, a node (via .install/services/adminer/vendor/
 * adminer-lesta-login.php, running inside the tenant's own Adminer pool) reaches back into
 * Laravel to read something, rather than Laravel ever reaching out to a node.
 *
 * Contrast with App\Actions\Backups\PrepareBackupDownload, which dispatches a real
 * agent-mediated Observe operation and is necessarily asynchronous (the owning node may not be
 * reachable again until its next heartbeat). This is synchronous because Adminer's own login
 * flow is itself synchronous and interactive: a human is sitting at a browser waiting for this
 * one request to resolve before Adminer can render its own login-submission response.
 */
class AdminerCredentialController extends Controller
{
    /**
     * database.tenant.v1's own fixed port for the tenant MariaDB instance (distinct from this
     * application's own control-plane database on 3306; see TenantDatabase's own class doc
     * comment and resources/docs/installation-guide.md's own node-capability table).
     */
    private const TENANT_DATABASE_PORT = 3307;

    /**
     * The tenant MariaDB instance's own fixed bind-address (.install/services/mariadb/install.sh:
     * "bind-address = 127.0.0.1" -- it never accepts a connection from anywhere but localhost).
     * Adminer's own pool always runs on the identical node as the TenantDatabase it is opened
     * for (PrepareAdminerSession resolves its eligible WebDomain by this database's own node_id),
     * so this is never a cross-node address -- the node's own external hostname would simply
     * never connect at all.
     */
    private const TENANT_DATABASE_HOST = '127.0.0.1';

    public function show(Request $request): JsonResponse
    {
        /** @var TenantDatabase $tenantDatabase */
        $tenantDatabase = $request->attributes->get('tenantDatabase');

        if ($tenantDatabase->isSuspended()) {
            abort(404);
        }

        return response()->json([
            'host' => self::TENANT_DATABASE_HOST,
            'port' => self::TENANT_DATABASE_PORT,
            'username' => $tenantDatabase->database_user,
            'password' => $tenantDatabase->password,
            'database' => $tenantDatabase->database_name,
        ]);
    }
}
