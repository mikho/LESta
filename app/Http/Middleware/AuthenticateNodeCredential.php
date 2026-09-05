<?php

namespace App\Http\Middleware;

use App\Enums\NodeEnrollmentStatus;
use App\Models\Node;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateNodeCredential
{
    /**
     * Handle an incoming request.
     *
     * Authenticates a bearer node credential against nodes.node_credential_hash and stores the
     * resolved, enrolled Node on the request's attribute bag under "node" for the controller to
     * read. This is machine-to-machine, bearer-token authentication over Laravel's own
     * already-terminated HTTPS, never a session or a Sanctum guard.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            abort(401, 'Missing bearer token.');
        }

        $node = Node::query()
            ->where('node_credential_hash', hash('sha256', $token))
            ->where('enrollment_status', NodeEnrollmentStatus::Enrolled->value)
            ->first();

        if ($node === null) {
            abort(401, 'Invalid or unenrolled node credential.');
        }

        $request->attributes->set('node', $node);

        return $next($request);
    }
}
