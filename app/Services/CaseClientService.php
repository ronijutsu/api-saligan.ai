<?php

namespace App\Services;

use App\Exceptions\CrmConflictException;
use App\Models\CaseClient;
use App\Models\Client;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CaseClientService
{
    public function linksForClient(User $user, Client $client, string $sort = 'updated_at', string $direction = 'desc'): Builder
    {
        return CaseClient::query()->where('client_id', $client->id)
            ->whereHas('case', function (Builder $query) use ($user, $client): void {
                $query->visibleTo($user)->where(function (Builder $scope) use ($client): void {
                    if ($client->organization_id !== null) {
                        $scope->where('organization_id', $client->organization_id);

                        return;
                    }

                    $scope->whereNull('organization_id')->where('user_id', $client->owner_user_id);
                });
            })
            ->with(['case.owner', 'case.assignees', 'client'])
            ->orderBy($sort, $direction)
            ->orderBy('case_id', $direction);
    }

    public function linksForCase(User $user, LegalCase $case, string $sort = 'updated_at', string $direction = 'desc'): Builder
    {
        return CaseClient::query()->where('case_id', $case->id)
            ->whereHas('client', function (Builder $query) use ($user, $case): void {
                $query->visibleTo($user)->where(function (Builder $scope) use ($case): void {
                    if ($case->organization_id !== null) {
                        $scope->where('organization_id', $case->organization_id);

                        return;
                    }

                    $scope->whereNull('organization_id')->where('owner_user_id', $case->user_id);
                });
            })
            ->with(['case', 'client'])
            ->orderBy($sort, $direction)
            ->orderBy('client_id', $direction);
    }

    public function attach(User $user, LegalCase $case, Client $client, string $type, bool $isPrimary): CaseClient
    {
        return DB::transaction(function () use ($user, $case, $client, $type, $isPrimary): CaseClient {
            $case = LegalCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $client = Client::query()->active()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $this->assertMutable($user, $case, $client);

            if ($isPrimary !== ($type === 'primary_client')) {
                throw new CrmConflictException('invalid_relationship', 'is_primary must agree with relationship_type.', 422);
            }

            if (DB::table('case_client')->where('case_id', $case->id)->where('client_id', $client->id)->exists()) {
                throw new CrmConflictException('duplicate_link', 'This client is already linked to the case.');
            }

            if ($isPrimary) {
                CaseClient::query()->where('case_id', $case->id)->where('is_primary', true)->update([
                    'is_primary' => false,
                    'relationship_type' => 'additional_client',
                    'updated_at' => now(),
                ]);
            }

            $link = CaseClient::query()->create([
                'case_id' => $case->id,
                'client_id' => $client->id,
                'relationship_type' => $type,
                'is_primary' => $isPrimary,
                'attached_by_user_id' => $user->id,
            ]);

            Log::info('CRM case client action', ['action' => 'attached', 'case_id' => $case->id, 'client_id' => $client->id, 'actor_id' => $user->id]);

            return $link->load(['case', 'client']);
        });
    }

    public function detach(User $user, LegalCase $case, Client $client, ?string $replacementId): void
    {
        DB::transaction(function () use ($user, $case, $client, $replacementId): void {
            $case = LegalCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $client = Client::query()->active()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $this->assertMutable($user, $case, $client);
            $link = CaseClient::query()->where('case_id', $case->id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();

            $remaining = CaseClient::query()->where('case_id', $case->id)->where('client_id', '!=', $client->id)->count();
            if ($link->is_primary && $remaining > 0) {
                if ($replacementId === null || $replacementId === $client->id) {
                    throw new CrmConflictException('primary_replacement_required', 'An explicit replacement client is required.');
                }

                $replacement = Client::query()->whereKey($replacementId)->lockForUpdate()->first();
                if ($replacement === null || ! Client::query()->visibleTo($user)->active()->whereKey($replacementId)->exists() || ! $this->sameScope($case, $replacement)) {
                    throw new CrmConflictException('invalid_primary_replacement', 'The replacement client is unavailable.');
                }

                $replacementLink = CaseClient::query()->where('case_id', $case->id)->where('client_id', $replacementId)->lockForUpdate()->first();
                if ($replacementLink === null) {
                    throw new CrmConflictException('invalid_primary_replacement', 'The replacement client must already be linked.');
                }
                CaseClient::query()->where('case_id', $case->id)->where('client_id', $client->id)->delete();
                CaseClient::query()->where('case_id', $case->id)->where('client_id', $replacementId)->update([
                    'is_primary' => true,
                    'relationship_type' => 'primary_client',
                    'updated_at' => now(),
                ]);
            }

            if (! $link->is_primary || $remaining === 0) {
                CaseClient::query()->where('case_id', $case->id)->where('client_id', $client->id)->delete();
            }
            Log::info('CRM case client action', ['action' => 'detached', 'case_id' => $case->id, 'client_id' => $client->id, 'actor_id' => $user->id]);
        });
    }

    private function assertMutable(User $user, LegalCase $case, Client $client): void
    {
        abort_unless($user->can('view', $case) && $user->can('update', $client) && $this->sameScope($case, $client), 403);
        if ($case->isReadOnly()) {
            throw new CrmConflictException('read_only_case', 'Closed or archived cases cannot change client links.');
        }
    }

    private function sameScope(LegalCase $case, Client $client): bool
    {
        if ($case->organization_id !== null || $client->organization_id !== null) {
            return $case->organization_id !== null
                && $case->organization_id === $client->organization_id;
        }

        return $case->user_id === $client->owner_user_id;
    }
}
