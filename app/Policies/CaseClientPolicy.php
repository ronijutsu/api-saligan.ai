<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\LegalCase;
use App\Models\User;

class CaseClientPolicy
{
    public function view(User $user, LegalCase $case, Client $client): bool
    {
        return $user->can('view', $case) && $user->can('view', $client);
    }

    public function mutate(User $user, LegalCase $case, Client $client): bool
    {
        return ! $case->isReadOnly()
            && $user->can('view', $case)
            && $user->can('update', $client);
    }
}
