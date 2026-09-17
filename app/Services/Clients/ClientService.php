<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\User;
use App\Support\CrmMutation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientService
{
    public function scopedQuery(User $user): Builder
    {
        return Client::query()->visibleTo($user);
    }

    public function create(User $user, array $attributes): Client
    {
        $client = new Client;
        $client->fill($attributes + ['lifecycle' => 'prospect']);
        $client->forceFill([
            'owner_user_id' => $user->id,
            'organization_id' => $user->hasActiveMembership() ? $user->organization_id : null,
        ]);
        $client->save();

        $this->audit($user, $client, 'created');

        return $client;
    }

    public function archive(User $user, Client $client, ?string $ifMatch = null): Client
    {
        return DB::transaction(function () use ($user, $client, $ifMatch): Client {
            $client = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();

            if ($client->archived_at === null) {
                CrmMutation::assertIfMatchValue($ifMatch, $client);
                $client->forceFill(['archived_at' => now()])->save();
                $this->audit($user, $client, 'archived');
            }

            return $client->fresh();
        });
    }

    public function update(User $user, Client $client, array $attributes, ?string $ifMatch = null): Client
    {
        return DB::transaction(function () use ($user, $client, $attributes, $ifMatch): Client {
            $client = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            CrmMutation::assertIfMatchValue($ifMatch, $client);
            $client->update($attributes);
            $this->audit($user, $client, 'updated');

            return $client->fresh();
        });
    }

    public function restore(User $user, Client $client, ?string $ifMatch = null): Client
    {
        return DB::transaction(function () use ($user, $client, $ifMatch): Client {
            $client = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            CrmMutation::assertIfMatchValue($ifMatch, $client);

            if ($client->archived_at !== null) {
                $client->forceFill(['archived_at' => null])->save();
                $this->audit($user, $client, 'restored');
            }

            return $client->fresh();
        });
    }

    private function audit(User $user, Client $client, string $action): void
    {
        Log::info('CRM client action', [
            'action' => $action,
            'client_id' => $client->id,
            'actor_id' => $user->id,
            'organization_id' => $client->organization_id,
        ]);
    }
}
