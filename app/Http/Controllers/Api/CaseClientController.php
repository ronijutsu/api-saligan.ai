<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttachCaseClientRequest;
use App\Http\Requests\DetachCaseClientRequest;
use App\Http\Resources\CaseClientResource;
use App\Models\Client;
use App\Models\LegalCase;
use App\Services\CaseClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CaseClientController extends Controller
{
    public function __construct(private readonly CaseClientService $links) {}

    public function clientIndex(Request $request, string $client): AnonymousResourceCollection
    {
        $validated = $this->validateListQuery($request);
        $model = $this->client($request, $client);
        $this->authorize('view', $model);

        return CaseClientResource::collection($this->links->linksForClient($request->user(), $model, $validated['sort'] ?? 'updated_at', $validated['direction'] ?? 'desc')->paginate($validated['per_page'] ?? 25, ['*'], 'page', $validated['page'] ?? 1));
    }

    public function caseIndex(Request $request, string $case): AnonymousResourceCollection
    {
        $validated = $this->validateListQuery($request);
        $model = $this->case($request, $case);
        $this->authorize('view', $model);

        return CaseClientResource::collection($this->links->linksForCase($request->user(), $model, $validated['sort'] ?? 'updated_at', $validated['direction'] ?? 'desc')->paginate($validated['per_page'] ?? 25, ['*'], 'page', $validated['page'] ?? 1));
    }

    public function attachToClient(AttachCaseClientRequest $request, string $client): JsonResponse
    {
        $clientModel = $this->client($request, $client);
        $case = LegalCase::query()->visibleTo($request->user())->whereKey($request->validated('case_id'))->firstOrFail();
        $link = $this->links->attach($request->user(), $case, $clientModel, $request->validated('relationship_type'), $request->boolean('is_primary'));

        return (new CaseClientResource($link))->response()->setStatusCode(201);
    }

    public function attachToCase(AttachCaseClientRequest $request, string $case): JsonResponse
    {
        $caseModel = $this->case($request, $case);
        $client = Client::query()->visibleTo($request->user())->active()->whereKey($request->validated('client_id'))->firstOrFail();
        $link = $this->links->attach($request->user(), $caseModel, $client, $request->validated('relationship_type'), $request->boolean('is_primary'));

        return (new CaseClientResource($link))->response()->setStatusCode(201);
    }

    public function detachFromClient(DetachCaseClientRequest $request, string $client, string $case): JsonResponse
    {
        $clientModel = $this->client($request, $client);
        $caseModel = LegalCase::query()->visibleTo($request->user())->whereKey($case)->firstOrFail();
        $this->links->detach($request->user(), $caseModel, $clientModel, $request->validated('replacement_client_id'));

        return response()->json(null, 204);
    }

    public function detachFromCase(DetachCaseClientRequest $request, string $case, string $client): JsonResponse
    {
        $caseModel = $this->case($request, $case);
        $clientModel = Client::query()->visibleTo($request->user())->active()->whereKey($client)->firstOrFail();
        $this->links->detach($request->user(), $caseModel, $clientModel, $request->validated('replacement_client_id'));

        return response()->json(null, 204);
    }

    private function client(Request $request, string $id): Client
    {
        return Client::query()->visibleTo($request->user())->active()->whereKey($id)->firstOrFail();
    }

    private function case(Request $request, string $id): LegalCase
    {
        return LegalCase::query()->visibleTo($request->user())->whereKey($id)->firstOrFail();
    }

    /**
     * @return array{page?: int, per_page?: int, sort?: string, direction?: string}
     */
    private function validateListQuery(Request $request): array
    {
        return $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in(['is_primary', 'relationship_type', 'created_at', 'updated_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
    }
}
