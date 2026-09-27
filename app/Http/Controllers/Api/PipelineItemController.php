<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MovePipelineItemRequest;
use App\Http\Requests\StorePipelineItemRequest;
use App\Http\Requests\UpdatePipelineItemRequest;
use App\Http\Resources\PipelineItemResource;
use App\Http\Resources\PipelineStageChangeResource;
use App\Models\PipelineItem;
use App\Services\Pipelines\PipelineItemService;
use App\Support\CrmMutation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PipelineItemController extends Controller
{
    public function __construct(private readonly PipelineItemService $items) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'pipeline_id' => ['nullable', 'uuid'],
            'stage_id' => ['nullable', 'uuid'],
            'client_id' => ['nullable', 'uuid'],
            'owner_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:255'],
            'archived' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in(['title', 'next_action_at', 'created_at', 'updated_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $query = $this->items->scopedQuery($request->user())
            ->with(['client', 'pipeline', 'stage'])
            ->when($request->boolean('archived'), fn ($query) => $query->archived(), fn ($query) => $query->active())
            ->when(isset($validated['pipeline_id']), fn ($query) => $query->where('pipeline_id', $validated['pipeline_id']))
            ->when(isset($validated['stage_id']), fn ($query) => $query->where('stage_id', $validated['stage_id']))
            ->when(isset($validated['client_id']), fn ($query) => $query->where('client_id', $validated['client_id']))
            ->when(isset($validated['owner_id']), fn ($query) => $query->where('owner_user_id', $validated['owner_id']))
            ->when(isset($validated['q']), function ($query) use ($validated): void {
                $query->where(function ($search) use ($validated): void {
                    $search->where('title', 'ilike', '%'.$validated['q'].'%')
                        ->orWhere('source', 'ilike', '%'.$validated['q'].'%');
                });
            })
            ->orderBy($validated['sort'] ?? 'updated_at', $validated['direction'] ?? 'desc')
            ->orderBy('id');

        return PipelineItemResource::collection($query->paginate($validated['per_page'] ?? 25));
    }

    public function store(StorePipelineItemRequest $request): JsonResponse
    {
        $item = $this->items->create(
            $request->user(),
            $request->validated(),
        );

        return CrmMutation::withEtag(
            (new PipelineItemResource($item))->response()->setStatusCode(201),
            $item,
        );
    }

    public function show(Request $request, string $item): JsonResponse
    {
        $model = $this->findScoped($request, $item, $request->boolean('archived'));

        return CrmMutation::withEtag((new PipelineItemResource($model))->response(), $model);
    }

    public function update(UpdatePipelineItemRequest $request, string $item): JsonResponse
    {
        CrmMutation::idempotencyKey($request);
        $model = $this->findScoped($request, $item);
        $updated = $this->items->update(
            $request->user(),
            $model,
            $request->validated(),
            $request->header('If-Match'),
        );

        return CrmMutation::withEtag((new PipelineItemResource($updated))->response(), $updated);
    }

    public function destroy(Request $request, string $item): JsonResponse
    {
        CrmMutation::idempotencyKey($request);
        $model = $this->findScoped($request, $item, null);
        $archived = $this->items->archive($request->user(), $model, $request->header('If-Match'));

        return CrmMutation::withEtag((new PipelineItemResource($archived))->response(), $archived);
    }

    public function restore(Request $request, string $item): JsonResponse
    {
        CrmMutation::idempotencyKey($request);
        $model = $this->findScoped($request, $item, true);
        $restored = $this->items->restore($request->user(), $model, $request->header('If-Match'));

        return CrmMutation::withEtag((new PipelineItemResource($restored))->response(), $restored);
    }

    public function move(MovePipelineItemRequest $request, string $item): JsonResponse
    {
        $model = $this->findScoped($request, $item, null);
        $moved = $this->items->move(
            $request->user(),
            $model,
            $request->validated('stage_id'),
            $request->header('If-Match'),
        );

        return CrmMutation::withEtag((new PipelineItemResource($moved))->response(), $moved);
    }

    public function history(Request $request, string $item): AnonymousResourceCollection
    {
        $model = $this->findScoped($request, $item, null);
        $this->authorize('view', $model);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $history = $model->history()
            ->with(['previousStage', 'newStage'])
            ->paginate($validated['per_page'] ?? 25);

        return PipelineStageChangeResource::collection($history);
    }

    private function findScoped(Request $request, string $id, ?bool $archived = false): PipelineItem
    {
        return $this->items->scopedQuery($request->user())
            ->when($archived === true, fn ($query) => $query->archived())
            ->when($archived === false, fn ($query) => $query->active())
            ->whereKey($id)
            ->with(['client', 'pipeline', 'stage'])
            ->firstOrFail();
    }
}
