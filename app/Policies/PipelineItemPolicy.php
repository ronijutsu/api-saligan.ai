<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\Pipeline;
use App\Models\PipelineItem;
use App\Models\User;

class PipelineItemPolicy
{
    public function view(User $user, PipelineItem $item): bool
    {
        return $this->inScope($user, $item);
    }

    public function update(User $user, PipelineItem $item): bool
    {
        if (! $this->inScope($user, $item)) {
            return false;
        }

        return $item->isOwnedBy($user) || ($user->hasActiveMembership() && $user->canManageOrganization());
    }

    public function delete(User $user, PipelineItem $item): bool
    {
        return $this->update($user, $item);
    }

    public function restore(User $user, PipelineItem $item): bool
    {
        return $this->update($user, $item);
    }

    public function move(User $user, PipelineItem $item): bool
    {
        return $this->update($user, $item);
    }

    private function inScope(User $user, PipelineItem $item): bool
    {
        if ($item->relationLoaded('pipeline') && $item->relationLoaded('client')) {
            return $this->recordIsInScope($user, $item->pipeline)
                && $this->recordIsInScope($user, $item->client);
        }

        return Pipeline::query()
            ->visibleTo($user)
            ->whereKey($item->pipeline_id)
            ->exists()
            && Client::query()
                ->visibleTo($user)
                ->whereKey($item->client_id)
                ->exists();
    }

    private function recordIsInScope(User $user, Pipeline|Client|null $record): bool
    {
        if ($record === null) {
            return false;
        }

        return $user->hasActiveMembership()
            ? $record->organization_id === $user->organization_id
            : $record->organization_id === null && $record->owner_user_id === $user->id;
    }
}
