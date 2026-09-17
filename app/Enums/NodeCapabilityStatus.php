<?php

namespace App\Enums;

/**
 * The underlying install/lifecycle state of a NodeCapability, tracked independently of
 * suspension: suspending/unsuspending (NodeCapability::suspended_at/suspension_source, via the
 * Suspendable trait) is a separate, admin-controlled overlay, and deliberately has no case here
 * -- a suspended capability keeps whatever status value it had underneath, so unsuspending it
 * reveals the truth rather than a value this enum would otherwise have to reconstruct.
 *
 * NotInstalled -> Running only ever happens via a real, confirmed agent heartbeat
 * (AgentHeartbeatController::store); an admin can never set Running directly (see
 * UpdateNodeCapabilityStatus, which rejects it outright). An admin CAN manually set Stopped or
 * reset to NotInstalled, since those record ground truth the agent has no way to assert on its
 * own (it only ever reports presence, never a confident "this was removed").
 */
enum NodeCapabilityStatus: string
{
    case NotInstalled = 'not_installed';
    case Running = 'running';
    case Stopped = 'stopped';
}
