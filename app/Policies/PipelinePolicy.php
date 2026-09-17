<?php

namespace App\Policies;

use App\Models\Pipeline;
use App\Models\User;

class PipelinePolicy
{
    public function view(User $user, Pipeline $pipeline): bool
    {
        return $this->inScope($user, $pipeline);
    }

    public function update(User $user, Pipeline $pipeline): bool
    {
        if (! $this->inScope($user, $pipeline)) {
            return false;
        }

        return $pipeline->isOwnedBy($user) || ($user->hasActiveMembership() && $user->canManageOrganization());
    }

    public function delete(User $user, Pipeline $pipeline): bool
    {
        return $this->update($user, $pipeline);
    }

    public function restore(User $user, Pipeline $pipeline): bool
    {
        return $this->update($user, $pipeline);
    }

    public function configureStages(User $user, Pipeline $pipeline): bool
    {
        return $this->update($user, $pipeline);
    }

    private function inScope(User $user, Pipeline $pipeline): bool
    {
        return $user->hasActiveMembership()
            ? $pipeline->organization_id === $user->organization_id
            : $pipeline->organization_id === null && $pipeline->owner_user_id === $user->id;
    }
}
