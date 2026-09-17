<?php

namespace App\Services\Pipelines;

use App\Exceptions\CrmConflictException;
use App\Models\Client;
use App\Models\Pipeline;
use App\Models\PipelineItem;
use App\Models\PipelineStage;
use App\Models\PipelineStageChange;
use App\Models\User;
use App\Support\CrmMutation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PipelineItemService
{
    public function scopedQuery(User $user): Builder
    {
        return PipelineItem::query()->visibleTo($user);
    }

    /**
     * Create an item and its initial stage-history row atomically.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, array $attributes): PipelineItem
    {
        return DB::transaction(function () use ($user, $attributes): PipelineItem {
            $pipeline = Pipeline::query()
                ->visibleTo($user)
                ->active()
                ->whereKey($attributes['pipeline_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $client = Client::query()
                ->visibleTo($user)
                ->active()
                ->whereKey($attributes['client_id'])
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($user)->authorize('update', $pipeline);
            Gate::forUser($user)->authorize('update', $client);
            $this->ensureSameScope($pipeline, $client);

            $stage = $pipeline->allStages()
                ->active()
                ->whereKey($attributes['stage_id'])
                ->lockForUpdate()
                ->first();

            if ($stage === null) {
                throw new CrmConflictException(
                    'invalid_pipeline_stage',
                    'The selected stage is not active in the selected pipeline.',
                    422,
                    ['stage_id' => ['The stage must belong to the selected pipeline.']],
                );
            }

            $item = new PipelineItem;
            $item->fill([
                'title' => $attributes['title'],
                'source' => $attributes['source'] ?? null,
                'note' => $attributes['note'] ?? null,
                'next_action_at' => $attributes['next_action_at'] ?? null,
            ]);
            $item->forceFill([
                'client_id' => $client->id,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'owner_user_id' => $user->id,
            ]);
            $item->save();

            PipelineStageChange::create([
                'pipeline_item_id' => $item->id,
                'previous_stage_id' => null,
                'new_stage_id' => $stage->id,
                'actor_user_id' => $user->id,
                'request_id' => (string) Str::uuid(),
            ]);

            $this->audit($user, $item, 'created', $stage->id);

            return $item->load(['client', 'pipeline', 'stage']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, PipelineItem $item, array $attributes, ?string $ifMatch = null): PipelineItem
    {
        Gate::forUser($user)->authorize('update', $item);

        return DB::transaction(function () use ($user, $item, $attributes, $ifMatch): PipelineItem {
            $item = $this->scopedQuery($user)->whereKey($item->id)->lockForUpdate()->firstOrFail();
            CrmMutation::assertIfMatchValue($ifMatch, $item);

            $item->fill($attributes);
            $item->save();
            $this->audit($user, $item, 'updated');

            return $item->load(['client', 'pipeline', 'stage']);
        });
    }

    public function archive(User $user, PipelineItem $item, ?string $ifMatch = null): PipelineItem
    {
        Gate::forUser($user)->authorize('delete', $item);

        return DB::transaction(function () use ($user, $item, $ifMatch): PipelineItem {
            $item = $this->scopedQuery($user)->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($item->archived_at === null) {
                CrmMutation::assertIfMatchValue($ifMatch, $item);
                $item->forceFill(['archived_at' => now()])->save();
                $this->audit($user, $item, 'archived');
            }

            return $item->load(['client', 'pipeline', 'stage']);
        });
    }

    public function restore(User $user, PipelineItem $item, ?string $ifMatch = null): PipelineItem
    {
        Gate::forUser($user)->authorize('restore', $item);

        return DB::transaction(function () use ($user, $item, $ifMatch): PipelineItem {
            $item = $this->scopedQuery($user)->whereKey($item->id)->lockForUpdate()->firstOrFail();
            CrmMutation::assertIfMatchValue($ifMatch, $item);

            $stageIsActive = PipelineStage::query()
                ->where('pipeline_id', $item->pipeline_id)
                ->whereKey($item->stage_id)
                ->active()
                ->exists();

            if (! $stageIsActive) {
                throw new CrmConflictException(
                    'archived_pipeline_stage',
                    'Restore the item after its pipeline stage has been restored or replaced.',
                );
            }

            $pipelineIsActive = Pipeline::query()
                ->visibleTo($user)
                ->whereKey($item->pipeline_id)
                ->active()
                ->exists();
            $clientIsActive = Client::query()
                ->visibleTo($user)
                ->whereKey($item->client_id)
                ->active()
                ->exists();

            if (! $pipelineIsActive || ! $clientIsActive) {
                throw new CrmConflictException(
                    'archived_item_dependency',
                    'The item cannot be restored while its pipeline or client is archived.',
                );
            }

            if ($item->archived_at !== null) {
                $item->forceFill(['archived_at' => null])->save();
                $this->audit($user, $item, 'restored');
            }

            return $item->load(['client', 'pipeline', 'stage']);
        });
    }

    public function move(
        User $user,
        PipelineItem $item,
        string $stageId,
        ?string $ifMatch = null,
    ): PipelineItem {
        Gate::forUser($user)->authorize('move', $item);

        return DB::transaction(function () use ($user, $item, $stageId, $ifMatch): PipelineItem {
            $item = $this->scopedQuery($user)->whereKey($item->id)->lockForUpdate()->firstOrFail();

            CrmMutation::assertIfMatchValue($ifMatch, $item);

            if ($item->archived_at !== null) {
                throw new CrmConflictException(
                    'archived_item',
                    'An archived intake item cannot be moved until it is restored.',
                );
            }

            $currentStage = PipelineStage::query()
                ->where('pipeline_id', $item->pipeline_id)
                ->whereKey($item->stage_id)
                ->lockForUpdate()
                ->firstOrFail();
            $newStage = PipelineStage::query()
                ->where('pipeline_id', $item->pipeline_id)
                ->active()
                ->whereKey($stageId)
                ->lockForUpdate()
                ->first();

            if ($newStage === null) {
                throw new CrmConflictException(
                    'invalid_pipeline_stage',
                    'The selected stage is not active in the item pipeline.',
                    422,
                    ['stage_id' => ['The stage must belong to the item pipeline.']],
                );
            }

            if ($currentStage->id === $newStage->id) {
                throw new CrmConflictException(
                    'same_stage',
                    'The intake item is already in that stage.',
                );
            }

            if ($currentStage->isTerminal()) {
                throw new CrmConflictException(
                    'terminal_stage',
                    'An item in a terminal stage cannot be moved.',
                );
            }

            $item->forceFill(['stage_id' => $newStage->id])->save();
            PipelineStageChange::create([
                'pipeline_item_id' => $item->id,
                'previous_stage_id' => $currentStage->id,
                'new_stage_id' => $newStage->id,
                'actor_user_id' => $user->id,
                'request_id' => (string) Str::uuid(),
            ]);

            $this->audit($user, $item, 'moved', $newStage->id, $currentStage->id);

            return $item->load(['client', 'pipeline', 'stage']);
        });
    }

    private function ensureSameScope(Pipeline $pipeline, Client $client): void
    {
        $sameOrganization = $pipeline->organization_id === $client->organization_id;
        $sameSoloOwner = $pipeline->organization_id !== null
            || $pipeline->owner_user_id === $client->owner_user_id;

        if (! $sameOrganization || ! $sameSoloOwner) {
            throw new CrmConflictException(
                'scope_mismatch',
                'The client and pipeline must belong to the same scope.',
                422,
                ['client_id' => ['The client is not in the pipeline scope.']],
            );
        }
    }

    private function audit(
        User $user,
        PipelineItem $item,
        string $action,
        ?string $stageId = null,
        ?string $previousStageId = null,
    ): void {
        Log::info('CRM pipeline item action', array_filter([
            'action' => $action,
            'pipeline_item_id' => $item->id,
            'pipeline_id' => $item->pipeline_id,
            'client_id' => $item->client_id,
            'stage_id' => $stageId,
            'previous_stage_id' => $previousStageId,
            'actor_id' => $user->id,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
