<?php

namespace App\Concerns;

/**
 * For a resource a node is told about: each change that has to reach the node raises its desired
 * state version, which the provisioning operation then carries.
 *
 * @property int $desired_state_version
 */
trait HasDesiredState
{
    public function bumpDesiredStateVersion(): void
    {
        $this->forceFill(['desired_state_version' => $this->desired_state_version + 1])->save();
    }
}
