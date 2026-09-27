<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function view(User $user, Client $client): bool
    {
        return $this->inScope($user, $client);
    }

    public function update(User $user, Client $client): bool
    {
        if (! $this->inScope($user, $client)) {
            return false;
        }

        return $client->isOwnedBy($user) || ($user->hasActiveMembership() && $user->canManageOrganization());
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->update($user, $client);
    }

    public function restore(User $user, Client $client): bool
    {
        return $this->update($user, $client);
    }

    private function inScope(User $user, Client $client): bool
    {
        return $user->hasActiveMembership()
            ? $client->organization_id === $user->organization_id
            : $client->organization_id === null && $client->owner_user_id === $user->id;
    }
}
