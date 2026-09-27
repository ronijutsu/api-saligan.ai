<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Services\Clients\ClientService;
use App\Support\CrmMutation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function __construct(private readonly ClientService $clients) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'client_type' => ['nullable', Rule::in(Client::TYPES)],
            'lifecycle' => ['nullable', Rule::in(Client::LIFECYCLES)],
            'owner_id' => ['nullable', 'integer'],
            'archived' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in(['display_name', 'client_type', 'lifecycle', 'created_at', 'updated_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $query = $this->clients->scopedQuery($request->user())
            ->withCount(['cases as linked_cases_count' => fn ($cases) => $cases->visibleTo($request->user())])
            ->when($request->boolean('archived'), fn ($query) => $query->archived(), fn ($query) => $query->active())
            ->when(isset($validated['q']), fn ($query) => $query->where('display_name', 'ilike', '%'.$validated['q'].'%'))
            ->when(isset($validated['client_type']), fn ($query) => $query->where('client_type', $validated['client_type']))
            ->when(isset($validated['lifecycle']), fn ($query) => $query->where('lifecycle', $validated['lifecycle']))
            ->when(isset($validated['owner_id']), fn ($query) => $query->where('owner_user_id', $validated['owner_id']))
            ->orderBy($validated['sort'] ?? 'updated_at', $validated['direction'] ?? 'desc')
            ->orderBy('id');

        return ClientResource::collection($query->paginate($validated['per_page'] ?? 25));
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        CrmMutation::idempotencyKey($request);
        $client = $this->clients->create($request->user(), $request->validated());

        return CrmMutation::withEtag(
            (new ClientResource($client))->response()->setStatusCode(201),
            $client,
        );
    }

    public function show(Request $request, string $client): ClientResource
    {
        $user = $request->user();
        $model = $this->findScoped($request, $client, $request->boolean('archived'));

        $model->load([
            'pipelineItems' => fn ($items) => $items
                ->visibleTo($user)
                ->with(['pipeline:id,name', 'stage:id,name'])
                ->orderByDesc('updated_at')
                ->orderBy('id'),
            'caseClients' => fn ($links) => $links
                ->whereHas('case', fn ($cases) => $cases->visibleTo($user))
                ->with('case'),
        ])->loadCount([
            'pipelineItems as pipeline_items_count' => fn ($items) => $items->visibleTo($user),
            'cases as linked_cases_count' => fn ($cases) => $cases->visibleTo($user),
        ]);

        return new ClientResource($model);
    }

    public function update(UpdateClientRequest $request, string $client): JsonResponse
    {
        $model = $this->findScoped($request, $client);
        Gate::forUser($request->user())->authorize('update', $model);
        CrmMutation::idempotencyKey($request);
        $updated = $this->clients->update(
            $request->user(),
            $model,
            $request->validated(),
            $request->header('If-Match'),
        );

        return CrmMutation::withEtag((new ClientResource($updated))->response(), $updated);
    }

    public function destroy(Request $request, string $client): JsonResponse
    {
        $model = $this->findScoped($request, $client, null);
        Gate::forUser($request->user())->authorize('delete', $model);
        CrmMutation::idempotencyKey($request);
        $archived = $this->clients->archive($request->user(), $model, $request->header('If-Match'));

        return CrmMutation::withEtag((new ClientResource($archived))->response(), $archived);
    }

    public function restore(Request $request, string $client): JsonResponse
    {
        $model = $this->findScoped($request, $client, true);
        Gate::forUser($request->user())->authorize('restore', $model);
        CrmMutation::idempotencyKey($request);
        $restored = $this->clients->restore($request->user(), $model, $request->header('If-Match'));

        return CrmMutation::withEtag((new ClientResource($restored))->response(), $restored);
    }

    private function findScoped(Request $request, string $id, ?bool $archived = false): Client
    {
        return $this->clients->scopedQuery($request->user())
            ->when($archived === true, fn ($query) => $query->archived())
            ->when($archived === false, fn ($query) => $query->active())
            ->whereKey($id)
            ->firstOrFail();
    }
}
