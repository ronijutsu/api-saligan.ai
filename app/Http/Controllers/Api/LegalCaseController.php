<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\LegalCaseResource;
use App\Models\LegalCase;
use App\Models\Message;
use App\Models\Template;
use App\Services\Cases\CaseDigestService;
use App\Support\PlanLimits;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LegalCaseController extends Controller
{
    public function __construct(
        private readonly CaseDigestService $digests,
    ) {}

    /**
     * The case types offered in the intake form.
     */
    public const CASE_TYPES = ['legal', 'hr', 'customer_support', 'administrative', 'general'];

    /**
     * The case statuses offered in the intake form.
     */
    public const STATUSES = ['open', 'in_progress', 'on_hold', 'closed'];

    /**
     * The priority levels offered in the intake form.
     */
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /**
     * List the authenticated user's cases with filters, search, and sorting.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // Owned cases plus the ones the user has been assigned to — the list is
        // "cases I can open", not "cases I created".
        $query = LegalCase::query()
            ->visibleTo($request->user())
            ->with(['owner', 'assignees'])
            ->withCount('messages')
            ->withCount(['tasks as open_tasks_count' => fn ($task) => $task->where('status', '!=', 'completed')])
            ->withCount('tasks as total_tasks_count')
            ->addSelect([
                'last_message_at' => Message::query()
                    ->select('messages.created_at')
                    ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                    ->whereColumn('conversations.case_id', 'cases.id')
                    ->latest('messages.created_at')
                    ->limit(1),
                'last_message_snippet' => Message::query()
                    ->selectRaw('left(messages.content, 120)')
                    ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                    ->whereColumn('conversations.case_id', 'cases.id')
                    ->latest('messages.created_at')
                    ->limit(1),
            ]);

        if ($request->boolean('archived')) {
            $query->onlyArchived();
        } else {
            $query->active();
        }

        $this->applyFilters($query, $request);
        $this->applySearch($query, $request->string('search')->toString());
        $this->applySort($query, $request->string('sort')->toString(), $request->string('dir')->toString());

        $cases = $query->paginate(50);

        foreach ($cases->getCollection() as $case) {
            $this->digests->queueIfMissing($case);
        }

        return LegalCaseResource::collection($cases);
    }

    /**
     * Create a case from the intake form, along with its conversation thread.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(request: $request));

        if (($validated['status'] ?? null) === 'closed') {
            $validated['closed_at'] = now();
        }

        PlanLimits::ensureActiveAccess($request->user());

        $limit = PlanLimits::limitFor($request->user(), 'active_cases');
        if ($limit !== null) {
            $active = $request->user()->cases()
                ->where('status', '!=', 'closed')
                ->whereNull('archived_at')
                ->count();

            if ($active >= $limit) {
                abort(response()->json([
                    'message' => 'Active case limit reached. Upgrade your plan to create more cases.',
                    'upgrade_required' => true,
                ], 402));
            }
        }

        $case = DB::transaction(function () use ($request, $validated) {
            $case = $request->user()->cases()->create($validated + [
                'reference' => $validated['reference'] ?? $this->nextReference(),
                // A case belongs to the firm its owner belongs to. Null for a
                // solo user, who has no organization — registration makes the
                // organization optional.
                'organization_id' => $request->user()->organization_id,
            ]);

            $case->conversations()->create([
                'user_id' => $request->user()->id,
                'purpose' => 'General',
                'title' => 'General',
            ]);

            return $case;
        });

        return (new LegalCaseResource($case->load(['conversations', 'conversation', 'defaultTemplate'])))->response()->setStatusCode(201);
    }

    /**
     * Show a case with its conversation threads, the active thread's messages,
     * and tasks. The active thread is chosen by the optional `conversation`
     * query parameter and falls back to the case's default thread.
     */
    public function show(Request $request, LegalCase $case): LegalCaseResource
    {
        $this->authorize('view', $case);

        $conversations = $case->conversations()
            ->withCount('messages')
            ->withMax('messages as last_message_at', 'created_at')
            ->with('labels')
            ->orderedByPinned()
            ->get();

        $activeConversation = $conversations
            ->firstWhere('id', $request->string('conversation')->toString())
            ?? $conversations->first();

        $case->setRelation('conversations', $conversations);
        $case->setRelation('conversation', $activeConversation);
        $case->setRelation(
            'messages',
            $activeConversation ? $activeConversation->messages()->orderBy('created_at')->get() : new Collection,
        );
        $case->load(['defaultTemplate', 'tasks', 'owner', 'assignees', 'caseClients.client']);
        $this->digests->queueIfMissing($case);

        return new LegalCaseResource($case);
    }

    /**
     * Update the case metadata (all intake fields are editable after creation).
     */
    public function update(Request $request, LegalCase $case): LegalCaseResource
    {
        $this->authorize('update', $case);

        $validated = $request->validate($this->rules(excludeRequired: true, request: $request, case: $case));

        if (array_key_exists('status', $validated)) {
            $validated['closed_at'] = $this->closedAtFor($case, $validated['status']);
        }

        $case->update($validated);

        return new LegalCaseResource($case->load('defaultTemplate'));
    }

    /**
     * Move a case between statuses without touching the rest of the record.
     * The edit form is a heavy way to answer "this is on hold now".
     */
    public function updateStatus(Request $request, LegalCase $case): LegalCaseResource
    {
        $this->authorize('update', $case);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', self::STATUSES)],
        ]);

        $validated['closed_at'] = $this->closedAtFor($case, $validated['status']);

        $case->update($validated);

        return new LegalCaseResource($case->load('defaultTemplate'));
    }

    /**
     * Duplicate a case, copying its metadata into a fresh container.
     */
    public function duplicate(Request $request, LegalCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        $copy = DB::transaction(function () use ($request, $case) {
            $copy = $request->user()->cases()->create([
                'title' => Str::limit('Copy of '.$case->title, 255),
                'case_type' => $case->case_type,
                'priority' => $case->priority,
                'status' => 'open',
                'description' => $case->description,
                'related_parties' => $case->related_parties,
                'due_date' => null,
                'tags' => $case->tags,
                'default_template_id' => $case->default_template_id,
                'reference' => $this->nextReference(),
            ]);

            $copy->conversations()->create([
                'user_id' => $request->user()->id,
                'purpose' => 'General',
                'title' => 'General',
            ]);

            return $copy;
        });

        return (new LegalCaseResource($copy->load(['conversations', 'conversation', 'defaultTemplate'])))->response()->setStatusCode(201);
    }

    /**
     * Create a new conversation thread for a case, scoped to a purpose.
     */
    public function storeConversation(Request $request, LegalCase $case): JsonResponse
    {
        $this->authorize('update', $case);

        $validated = $request->validate([
            'purpose' => ['nullable', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $purpose = $validated['purpose'] ?? null;

        $conversation = $case->conversations()->create([
            'user_id' => $request->user()->id,
            'purpose' => $purpose,
            'title' => $validated['title'] ?? $purpose,
        ]);

        return (new ConversationResource($conversation))->response()->setStatusCode(201);
    }

    /**
     * Restore an archived case.
     */
    public function restore(Request $request, LegalCase $case): LegalCaseResource
    {
        $this->authorize('delete', $case);

        $case->update(['archived_at' => null]);

        return new LegalCaseResource($case);
    }

    /**
     * Archive a case (soft delete).
     */
    public function destroy(Request $request, LegalCase $case): JsonResponse
    {
        $this->authorize('delete', $case);

        $case->update(['archived_at' => now()]);

        return response()->json(['message' => 'Case archived']);
    }

    /**
     * Permanently delete a case. Requires typing the case title to confirm.
     */
    public function forceDestroy(Request $request, LegalCase $case): JsonResponse
    {
        $this->authorize('delete', $case);

        $validated = $request->validate([
            'confirmation' => ['required', 'string'],
        ]);

        abort_unless($validated['confirmation'] === $case->title, 422, 'Confirmation phrase must match the case title.');

        $case->conversations()->delete();
        $case->delete();

        return response()->json(null, 204);
    }

    /**
     * The closed_at timestamp to store when a case moves to a status.
     *
     * Closing starts the 30-day auto-archive countdown, so entering "closed"
     * stamps the moment; leaving it clears the stamp so a later close restarts
     * the countdown. Unrelated edits never touch the existing value.
     */
    protected function closedAtFor(LegalCase $case, string $newStatus): ?string
    {
        if ($newStatus === 'closed' && $case->status !== 'closed') {
            return now();
        }

        if ($newStatus !== 'closed' && $case->status === 'closed') {
            return null;
        }

        return $case->closed_at;
    }

    /**
     * The shared intake/update validation rules.
     *
     * @return array<string, mixed>
     */
    protected function rules(bool $excludeRequired = false, ?Request $request = null, ?LegalCase $case = null): array
    {
        $required = fn (string $rule): array => $excludeRequired ? ['sometimes', $rule] : ['required', $rule];
        $dueDateRules = ['nullable', 'date'];

        // Preserve an overdue deadline already recorded on an existing case,
        // but do not allow a new deadline to be set in the past.
        if ($case === null || $request?->input('due_date') !== $case->due_date?->toDateString()) {
            $dueDateRules[] = 'after_or_equal:today';
        }

        return [
            'title' => [...$required('string'), 'max:255'],
            'case_type' => [...$required('string'), 'max:40'],
            // The edit form resubmits the case's own reference unchanged, so
            // the case under edit has to be exempt from its own uniqueness
            // check — otherwise every save fails against the stored row.
            // References are only required to be unique per user, so two users
            // may reference their cases independently.
            'reference' => [
                'nullable',
                'string',
                'max:40',
                Rule::unique('cases', 'reference')
                    ->where(fn ($query) => $query->where('user_id', $request?->user()?->getKey()))
                    ->ignore($case?->id),
            ],
            'priority' => ['nullable', 'in:'.implode(',', self::PRIORITIES)],
            'status' => [...$required('string'), 'in:'.implode(',', self::STATUSES)],
            'description' => ['nullable', 'string'],
            'related_parties' => ['nullable', 'array'],
            'related_parties.*' => ['string', 'max:255'],
            'due_date' => $dueDateRules,
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            // Only system templates, the user's own templates, or templates
            // owned by other members of the user's organization may be set as
            // the case default, so a case can never reference another user's
            // private template.
            // Checked through the model rather than Rule::exists(), whose
            // closure receives a plain query builder that cannot run the
            // Eloquent visibleTo scope.
            'default_template_id' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                    $template = Template::query()->whereKey($value)->first();

                    if ($template === null || ! $template->visibleTo($request->user())) {
                        $fail('The selected default template is unavailable.');
                    }
                },
            ],
        ];
    }

    /**
     * Apply status/type/priority/tag filters.
     */
    protected function applyFilters($query, Request $request): void
    {
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('case_type')) {
            $query->where('case_type', $request->string('case_type'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority'));
        }

        if ($request->filled('tag')) {
            $query->whereJsonContains('tags', $request->string('tag'));
        }

        // Narrow to the cases one colleague is on. "On the case" means owner or
        // assignee — the same reading the list rows use, so filtering by a
        // person never hides a matter they actually hold. "me" saves the client
        // from having to know its own id.
        if ($request->filled('assignee')) {
            $assignee = $request->string('assignee')->toString();
            $userId = $assignee === 'me' ? $request->user()->id : $assignee;

            $query->where(function ($scoped) use ($userId) {
                $scoped->where('user_id', $userId)
                    ->orWhereHas('assignees', fn ($member) => $member->whereKey($userId));
            });
        }
    }

    /**
     * Apply a free-text search across title, description, and tags.
     */
    protected function applySearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function ($q) use ($search) {
            $q->whereLike('title', "%{$search}%")
                ->orWhereLike('description', "%{$search}%")
                ->orWhereLike('reference', "%{$search}%")
                ->orWhereJsonContains('tags', $search);
        });
    }

    /**
     * Apply sort by a whitelisted column.
     */
    protected function applySort($query, string $sort, string $dir): void
    {
        $column = in_array($sort, ['title', 'due_date', 'priority', 'status', 'created_at', 'updated_at'], true)
            ? $sort
            : 'updated_at';

        $query->orderBy($column, $dir === 'asc' ? 'asc' : 'desc');
    }

    /**
     * Generate the next case reference, e.g. CASE-2026-0001.
     */
    protected function nextReference(): string
    {
        $year = now()->year;
        $count = LegalCase::where('reference', 'like', "CASE-{$year}-%")->count();

        return sprintf('CASE-%d-%04d', $year, $count + 1);
    }
}
