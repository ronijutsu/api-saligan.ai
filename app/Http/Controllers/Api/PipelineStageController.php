<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ArchivePipelineStageRequest;
use App\Http\Requests\ReorderPipelineStagesRequest;
use App\Http\Requests\RestorePipelineStageRequest;
use App\Http\Requests\StorePipelineStageRequest;
use App\Http\Requests\UpdatePipelineStageRequest;
use App\Http\Resources\PipelineResource;
use App\Http\Resources\PipelineStageResource;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\Pipelines\PipelineService;
use App\Support\CrmMutation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineStageController extends Controller
{
    public function __construct(private readonly PipelineService $pipelines) {}

    public function store(StorePipelineStageRequest $request, string $pipeline): JsonResponse
    {
        CrmMutation::idempotencyKey($request);
        $model = $this->findPipeline($request, $pipeline);
        $stage = $this->pipelines->createStage($request->user(), $model, $request->validated());

        return CrmMutation::withEtag(
            (new PipelineStageResource($stage))->response()->setStatusCode(201),
            $stage,
        );
    }

    public function update(UpdatePipelineStageRequest $request, string $pipeline, string $stage): JsonResponse
    {
        CrmMutation::idempotencyKey($request);
        $model = $this->findPipeline($request, $pipeline);
        $stageModel = $this->findStage($model, $stage);
        $updated = $this->pipelines->updateStage(
            $request->user(),
            $model,
            $stageModel,
            $request->validated(),
            $request->header('If-Match'),
        );

        return CrmMutation::withEtag((new PipelineStageResource($updated))->response(), $updated);
    }

    public function destroy(ArchivePipelineStageRequest $request, string $pipeline, string $stage): JsonResponse
    {
        CrmMutation::idempotencyKey($request);
        $model = $this->findPipeline($request, $pipeline);
        $stageModel = $this->findStage($model, $stage);
        $archived = $this->pipelines->archiveStage(
            $request->user(),
            $model,
            $stageModel,
            $request->validated('replacement_stage_id'),
            $request->header('If-Match'),
        );

        return CrmMutation::withEtag((new PipelineStageResource($archived))->response(), $archived);
    }

    public function restore(RestorePipelineStageRequest $request, string $pipeline, string $stage): JsonResponse
    {
        CrmMutation::idempotencyKey($request);
        $model = $this->findPipeline($request, $pipeline);
        $stageModel = $this->findStage($model, $stage, true);
        $restored = $this->pipelines->restoreStage(
            $request->user(),
            $model,
            $stageModel,
            $request->header('If-Match'),
        );

        return CrmMutation::withEtag((new PipelineStageResource($restored))->response(), $restored);
    }

    public function reorder(ReorderPipelineStagesRequest $request, string $pipeline): JsonResponse
    {
        CrmMutation::idempotencyKey($request);
        $model = $this->findPipeline($request, $pipeline);
        $updated = $this->pipelines->reorder(
            $request->user(),
            $model,
            $request->validated('stage_ids'),
            $request->header('If-Match'),
        );

        return CrmMutation::withEtag((new PipelineResource($updated))->response(), $updated);
    }

    private function findPipeline(Request $request, string $id): Pipeline
    {
        return $this->pipelines->scopedQuery($request->user())
            ->active()
            ->whereKey($id)
            ->firstOrFail();
    }

    private function findStage(Pipeline $pipeline, string $id, bool $archived = false): PipelineStage
    {
        return $pipeline->allStages()
            ->when($archived, fn ($query) => $query->archived(), fn ($query) => $query->active())
            ->whereKey($id)
            ->firstOrFail();
    }
}
