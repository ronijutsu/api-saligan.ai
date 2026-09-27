<?php

namespace App\Services\Pipelines;

use App\Exceptions\CrmConflictException;
use App\Models\Pipeline;
use App\Models\PipelineItem;
use App\Models\PipelineStage;
use App\Models\PipelineStageChange;
use App\Models\User;
use App\Support\CrmMutation;
use App\Support\PipelineTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class PipelineService
{
    /**
     * Return every pipeline visible in the authenticated user's scope.
     */
    public function scopedQuery(User $user): Builder
    {
        return Pipeline::query()->visibleTo($user);
    }

    /**
     * Create a pipeline and its initial ordered stages atomically.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, array $attributes): Pipeline
    {
        $this->authorizeConfiguration($user);
        $template = isset($attributes['template_key']) ? PipelineTemplate::find($attributes['template_key']) : null;
        $stages = array_values($template['stages'] ?? $attributes['stages'] ?? PipelineStage::DEFAULT_STAGES);
        if ($template !== null) {
            if (! array_key_exists('name', $attributes)) {
                $attributes['name'] = $template['name'];
            }

            if (! array_key_exists('description', $attributes)) {
                $attributes['description'] = $template['description'];
            }
        }
        unset($attributes['stages']);

        return DB::transaction(function () use ($user, $attributes, $stages, $template): Pipeline {
            $isDefault = (bool) ($attributes['is_default'] ?? false)
                || ! $this->scopedQuery($user)->active()->exists();

            if ($isDefault) {
                $this->clearDefault($user);
            }

            $pipeline = new Pipeline;
            $pipeline->fill($attributes);
            $pipeline->forceFill([
                'owner_user_id' => $user->id,
                'organization_id' => $user->hasActiveMembership() ? $user->organization_id : null,
                'is_default' => $isDefault,
                'template_key' => $template['template_key'] ?? null,
                'auto_provisioned' => false,
            ]);
            $pipeline->save();

            foreach ($stages as $position => $stageAttributes) {
                $this->createStageRecord($pipeline, $stageAttributes, (int) $position);
            }

            $this->audit($user, $pipeline, 'created');

            return $pipeline->load('stages');
        });
    }

    /**
     * @return array{pipeline: Pipeline, created: bool}
     */
    public function provision(User $user, ?string $templateKey = null, ?string $requestId = null): array
    {
        $this->authorizeConfiguration($user);
        $template = PipelineTemplate::find($templateKey ?? PipelineTemplate::DEFAULT_KEY);

        if ($template === null) {
            throw new CrmConflictException(
                'invalid_template',
                'The selected pipeline template is not approved.',
                422,
                ['template_key' => ['The selected pipeline template is invalid.']],
            );
        }

        return DB::transaction(function () use ($user, $template, $requestId): array {
            if ($user->hasActiveMembership()) {
                $user->organization()->lockForUpdate()->firstOrFail();
            } else {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            }

            $query = $this->scopedQuery($user);
            $existing = $query->where('auto_provisioned', true)->with('stages')->first();

            if ($existing !== null) {
                return ['pipeline' => $existing, 'created' => false];
            }

            if ($query->active()->exists()) {
                throw new CrmConflictException(
                    'pipeline_already_exists',
                    'An active pipeline already exists in this scope.',
                );
            }

            $pipeline = $this->create($user, [
                'name' => $template['name'],
                'description' => $template['description'],
                'template_key' => $template['template_key'],
                'stages' => $template['stages'],
                'is_default' => true,
                'auto_provisioned' => true,
            ]);
            $pipeline->forceFill(['auto_provisioned' => true])->save();
            $this->audit($user, $pipeline, 'pipeline_auto_provisioned', null, $requestId, $template['template_key'], 'created');

            return ['pipeline' => $pipeline->load('stages'), 'created' => true];
        });
    }

    /**
     * Update pipeline metadata without allowing scope or archive fields through.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, Pipeline $pipeline, array $attributes, ?string $ifMatch = null): Pipeline
    {
        Gate::forUser($user)->authorize('update', $pipeline);

        return DB::transaction(function () use ($user, $pipeline, $attributes, $ifMatch): Pipeline {
            $pipeline = $this->scopedQuery($user)
                ->whereKey($pipeline->id)
                ->lockForUpdate()
                ->firstOrFail();
            CrmMutation::assertIfMatchValue($ifMatch, $pipeline);

            if (array_key_exists('is_default', $attributes) && $attributes['is_default'] === true) {
                $this->clearDefault($user, $pipeline->id);
            }

            if (array_key_exists('is_default', $attributes) && $attributes['is_default'] === false && $pipeline->is_default) {
                $replacement = $this->scopedQuery($user)
                    ->active()
                    ->whereKeyNot($pipeline->id)
                    ->orderBy('id')
                    ->first();

                if ($replacement === null) {
                    throw new CrmConflictException(
                        'default_pipeline_required',
                        'At least one active pipeline must remain the default pipeline.',
                    );
                }

                $pipeline->forceFill(['is_default' => false])->save();
                $replacement->forceFill(['is_default' => true])->save();
            }

            $pipeline->fill($attributes);
            $pipeline->save();
            $this->audit($user, $pipeline, 'updated');

            return $pipeline->load('stages');
        });
    }

    public function archive(User $user, Pipeline $pipeline, ?string $ifMatch = null): Pipeline
    {
        Gate::forUser($user)->authorize('delete', $pipeline);

        return DB::transaction(function () use ($user, $pipeline, $ifMatch): Pipeline {
            $pipeline = $this->scopedQuery($user)
                ->whereKey($pipeline->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($pipeline->archived_at !== null) {
                return $pipeline->load('stages');
            }

            CrmMutation::assertIfMatchValue($ifMatch, $pipeline);

            if ($pipeline->items()->active()->exists()) {
                throw new CrmConflictException(
                    'pipeline_has_active_items',
                    'Archive or move the active intake items before archiving this pipeline.',
                );
            }

            $wasDefault = $pipeline->is_default;
            $pipeline->forceFill([
                'archived_at' => now(),
                'is_default' => false,
            ])->save();

            if ($wasDefault) {
                $replacement = $this->scopedQuery($user)
                    ->active()
                    ->orderBy('id')
                    ->first();

                $replacement?->forceFill(['is_default' => true])->save();
            }

            $this->audit($user, $pipeline, 'archived');

            return $pipeline->load('stages');
        });
    }

    public function restore(User $user, Pipeline $pipeline, ?string $ifMatch = null): Pipeline
    {
        Gate::forUser($user)->authorize('restore', $pipeline);

        return DB::transaction(function () use ($user, $pipeline, $ifMatch): Pipeline {
            $pipeline = $this->scopedQuery($user)
                ->archived()
                ->whereKey($pipeline->id)
                ->lockForUpdate()
                ->firstOrFail();
            CrmMutation::assertIfMatchValue($ifMatch, $pipeline);

            if (! $pipeline->allStages()->active()->exists()) {
                throw new CrmConflictException(
                    'pipeline_without_active_stage',
                    'A pipeline must have at least one active stage before it can be restored.',
                );
            }

            $pipeline->forceFill(['archived_at' => null])->save();

            if (! $this->scopedQuery($user)->active()->where('is_default', true)->exists()) {
                $pipeline->forceFill(['is_default' => true])->save();
            }

            $this->audit($user, $pipeline, 'restored');

            return $pipeline->load('stages');
        });
    }

    /**
     * Append a stage to a pipeline's active ordered set.
     *
     * @param  array{name: string, outcome_kind: string}  $attributes
     */
    public function createStage(User $user, Pipeline $pipeline, array $attributes): PipelineStage
    {
        Gate::forUser($user)->authorize('configureStages', $pipeline);

        return DB::transaction(function () use ($user, $pipeline, $attributes): PipelineStage {
            $pipeline = $this->lockedPipeline($user, $pipeline);

            $this->ensurePipelineActive($pipeline);
            $this->ensureStageNameAvailable($pipeline, $attributes['name']);

            $position = ((int) ($pipeline->allStages()->active()->max('position'))) + 1;
            $stage = $this->createStageRecord($pipeline, $attributes, $position);
            $this->audit($user, $pipeline, 'stage_created', $stage->id);

            return $stage;
        });
    }

    /**
     * Update stage metadata while preserving its pipeline and position.
     *
     * @param  array<string, string>  $attributes
     */
    public function updateStage(
        User $user,
        Pipeline $pipeline,
        PipelineStage $stage,
        array $attributes,
        ?string $ifMatch = null,
    ): PipelineStage {
        Gate::forUser($user)->authorize('configureStages', $pipeline);

        return DB::transaction(function () use ($user, $pipeline, $stage, $attributes, $ifMatch): PipelineStage {
            $pipeline = $this->lockedPipeline($user, $pipeline);
            $stage = $pipeline->allStages()->active()->whereKey($stage->id)->lockForUpdate()->firstOrFail();
            CrmMutation::assertIfMatchValue($ifMatch, $stage);

            if (array_key_exists('name', $attributes)) {
                $this->ensureStageNameAvailable($pipeline, $attributes['name'], $stage->id);
            }

            $stage->fill($attributes);
            $stage->save();
            $this->audit($user, $pipeline, 'stage_updated', $stage->id);

            return $stage;
        });
    }

    /**
     * Archive a stage, reassigning its active items in the same transaction when
     * the caller supplies a valid replacement stage.
     */
    public function archiveStage(
        User $user,
        Pipeline $pipeline,
        PipelineStage $stage,
        ?string $replacementStageId = null,
        ?string $ifMatch = null,
    ): PipelineStage {
        Gate::forUser($user)->authorize('configureStages', $pipeline);

        return DB::transaction(function () use ($user, $pipeline, $stage, $replacementStageId, $ifMatch): PipelineStage {
            $pipeline = $this->lockedPipeline($user, $pipeline);
            $stage = $pipeline->allStages()->active()->whereKey($stage->id)->lockForUpdate()->firstOrFail();
            CrmMutation::assertIfMatchValue($ifMatch, $stage);

            if ($pipeline->allStages()->active()->count() < 2) {
                throw new CrmConflictException(
                    'last_active_stage',
                    'A pipeline must retain at least one active stage.',
                );
            }

            $items = PipelineItem::query()
                ->where('pipeline_id', $pipeline->id)
                ->where('stage_id', $stage->id)
                ->active()
                ->lockForUpdate()
                ->get();

            $replacement = null;

            if ($items->isNotEmpty()) {
                if ($replacementStageId === null) {
                    throw new CrmConflictException(
                        'stage_in_use',
                        'Choose a replacement stage before archiving a stage that has active items.',
                        409,
                        ['replacement_stage_id' => ['A replacement stage is required.']],
                    );
                }

                $replacement = $pipeline->allStages()
                    ->active()
                    ->whereKey($replacementStageId)
                    ->lockForUpdate()
                    ->first();

                if ($replacement === null || $replacement->id === $stage->id) {
                    throw new CrmConflictException(
                        'invalid_stage_replacement',
                        'The replacement stage must be another active stage in this pipeline.',
                        409,
                        ['replacement_stage_id' => ['The replacement stage is invalid.']],
                    );
                }
            }

            foreach ($items as $item) {
                $item->forceFill(['stage_id' => $replacement->id])->save();
                PipelineStageChange::create([
                    'pipeline_item_id' => $item->id,
                    'previous_stage_id' => $stage->id,
                    'new_stage_id' => $replacement->id,
                    'actor_user_id' => $user->id,
                ]);
            }

            $stage->forceFill(['archived_at' => now()])->save();
            $this->audit($user, $pipeline, 'stage_archived', $stage->id);

            return $stage;
        });
    }

    public function restoreStage(
        User $user,
        Pipeline $pipeline,
        PipelineStage $stage,
        ?string $ifMatch = null,
    ): PipelineStage {
        Gate::forUser($user)->authorize('configureStages', $pipeline);

        return DB::transaction(function () use ($user, $pipeline, $stage, $ifMatch): PipelineStage {
            $pipeline = $this->lockedPipeline($user, $pipeline);
            $stage = $pipeline->allStages()->archived()->whereKey($stage->id)->lockForUpdate()->firstOrFail();
            CrmMutation::assertIfMatchValue($ifMatch, $stage);
            $this->ensureStageNameAvailable($pipeline, $stage->name);

            $position = $stage->position;
            $positionOccupied = $pipeline->allStages()
                ->active()
                ->where('position', $position)
                ->exists();

            if ($positionOccupied) {
                $position = ((int) $pipeline->allStages()->active()->max('position')) + 1;
            }

            $stage->forceFill([
                'position' => $position,
                'archived_at' => null,
            ])->save();
            $this->audit($user, $pipeline, 'stage_restored', $stage->id);

            return $stage;
        });
    }

    /**
     * Reorder the complete active stage set atomically.
     *
     * @param  array<int, string>  $stageIds
     */
    public function reorder(User $user, Pipeline $pipeline, array $stageIds, ?string $ifMatch = null): Pipeline
    {
        Gate::forUser($user)->authorize('configureStages', $pipeline);

        return DB::transaction(function () use ($user, $pipeline, $stageIds, $ifMatch): Pipeline {
            $pipeline = $this->lockedPipeline($user, $pipeline);
            CrmMutation::assertIfMatchValue($ifMatch, $pipeline);
            $stages = $pipeline->allStages()->active()->lockForUpdate()->get();
            $stageMap = $stages->keyBy('id');

            if ($stages->count() !== count($stageIds) || collect($stageIds)->duplicates()->isNotEmpty() || collect($stageIds)->diff($stageMap->keys())->isNotEmpty()) {
                throw new CrmConflictException(
                    'invalid_stage_order',
                    'stage_ids must contain every active stage exactly once.',
                    422,
                    ['stage_ids' => ['The active stage set is incomplete or contains an invalid stage.']],
                );
            }

            $offset = max(((int) $stages->max('position')) + $stages->count() + 1, $stages->count() + 1);

            foreach ($stages as $stage) {
                $stage->forceFill(['position' => $stage->position + $offset])->save();
            }

            foreach ($stageIds as $position => $stageId) {
                $stageMap[$stageId]->forceFill(['position' => $position])->save();
            }

            $this->audit($user, $pipeline, 'stages_reordered');

            return $pipeline->load('stages');
        });
    }

    /**
     * @param  array{name: string, outcome_kind: string}  $attributes
     */
    private function createStageRecord(Pipeline $pipeline, array $attributes, int $position): PipelineStage
    {
        $stage = new PipelineStage;
        $stage->fill($attributes);
        $stage->forceFill([
            'pipeline_id' => $pipeline->id,
            'position' => $position,
        ]);
        $stage->save();

        return $stage;
    }

    private function lockedPipeline(User $user, Pipeline $pipeline): Pipeline
    {
        return $this->scopedQuery($user)
            ->active()
            ->whereKey($pipeline->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ensurePipelineActive(Pipeline $pipeline): void
    {
        if ($pipeline->archived_at !== null) {
            throw new CrmConflictException(
                'archived_pipeline',
                'An archived pipeline cannot be changed until it is restored.',
            );
        }
    }

    private function ensureStageNameAvailable(Pipeline $pipeline, string $name, ?string $ignoredStageId = null): void
    {
        $exists = $pipeline->allStages()
            ->active()
            ->where('name', $name)
            ->when($ignoredStageId !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoredStageId))
            ->exists();

        if ($exists) {
            throw new CrmConflictException(
                'duplicate_stage_name',
                'Stage names must be unique within a pipeline.',
                422,
                ['name' => ['A stage with this name already exists in the pipeline.']],
            );
        }
    }

    private function clearDefault(User $user, ?string $exceptPipelineId = null): void
    {
        $this->scopedQuery($user)
            ->when($exceptPipelineId !== null, fn (Builder $query): Builder => $query->whereKeyNot($exceptPipelineId))
            ->update(['is_default' => false]);
    }

    private function audit(
        User $user,
        Pipeline $pipeline,
        string $action,
        ?string $stageId = null,
        ?string $requestId = null,
        ?string $templateKey = null,
        ?string $result = null,
    ): void {
        Log::info('CRM pipeline action', array_filter([
            'action' => $action,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stageId,
            'actor_id' => $user->id,
            'organization_id' => $pipeline->organization_id,
            'request_id' => $requestId,
            'template_key' => $templateKey,
            'result' => $result,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function authorizeConfiguration(User $user): void
    {
        if ($user->hasActiveMembership() && ! $user->canManageOrganization()) {
            abort(403);
        }
    }
}
