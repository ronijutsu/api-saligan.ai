<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePipelineRequest;
use App\Http\Requests\UpdatePipelineRequest;
use App\Http\Resources\PipelineResource;
use App\Models\Pipeline;
use App\Services\Pipelines\PipelineService;
use App\Support\CrmMutation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PipelineController extends Controller
{
    public function __construct(private readonly PipelineService $pipelines) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'archived' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in(['name', 'created_at', 'updated_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $query = $this->pipelines->scopedQuery($request->user())
            ->with('stages')
            ->when($request->boolean('archived'), fn ($query) => $query->archived(), fn ($query) => $query->active())
            ->when(isset($validated['q']), fn ($query) => $query->where('name', 'ilike', '%'.$validated['q'].'%'))
            ->orderBy($validated['sort'] ?? 'updated_at', $validated['direction'] ?? 'desc')
            ->orderBy('id');

        return PipelineResource::collection($query->paginate($validated['per_page'] ?? 25));
    }

    public function store(StorePipelineRequest $request): JsonResponse
    {
        CrmMutation::idempotencyKey($request);

        $pipeline = $this->pipelines->create($request->user(), $request->validated());

        return CrmMutation::withEtag(
            (new PipelineResource($pipeline))->response()->setStatusCode(201),
            $pipeline,
        );
    }

    public function show(Request $request, string $pipeline): JsonResponse
    {
        $model = $this->findScoped($request, $pipeline, $request->boolean('archived'));

        return CrmMutation::withEtag((new PipelineResource($model))->response(), $model);
    }

    public function update(UpdatePipelineRequest $request, string $pipeline): JsonResponse
    {
        CrmMutation::idempotencyKey($request);
        $model = $this->findScoped($request, $pipeline);
        $updated = $this->pipelines->update(
            $request->user(),
            $model,
            $request->validated(),
            $request->header('If-Match'),
        );

        return CrmMutation::withEtag((new PipelineResource($updated))->response(), $updated);
    }

    public function destroy(Request $request, string $pipeline): JsonResponse
    {
        CrmMutation::idempotencyKey($request);
        $model = $this->findScoped($request, $pipeline, null);
        $archived = $this->pipelines->archive($request->user(), $model, $request->header('If-Match'));

        return CrmMutation::withEtag((new PipelineResource($archived))->response(), $archived);
    }

    public function restore(Request $request, string $pipeline): JsonResponse
    {
        CrmMutation::idempotencyKey($request);
        $model = $this->findScoped($request, $pipeline, true);
        $restored = $this->pipelines->restore($request->user(), $model, $request->header('If-Match'));

        return CrmMutation::withEtag((new PipelineResource($restored))->response(), $restored);
    }

    private function findScoped(Request $request, string $id, ?bool $archived = false): Pipeline
    {
        return $this->pipelines->scopedQuery($request->user())
            ->when($archived === true, fn ($query) => $query->archived())
            ->when($archived === false, fn ($query) => $query->active())
            ->whereKey($id)
            ->with('stages')
            ->firstOrFail();
    }
}
