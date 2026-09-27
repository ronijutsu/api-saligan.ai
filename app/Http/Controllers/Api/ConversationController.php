<?php

namespace App\Http\Controllers\Api;

use App\Enums\LabelKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\Label;
use App\Models\LegalCase;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;

class ConversationController extends Controller
{
    /**
     * List the authenticated user's conversations, optionally narrowed to the
     * threads carrying particular tags.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'tag_id' => ['nullable', 'array'],
            'tag_id.*' => ['uuid'],
            'match' => ['nullable', 'in:any,all'],
        ]);

        $tagIds = $validated['tag_id'] ?? [];

        $conversations = $request->user()->conversations()
            ->withCount('messages')
            ->with(['labels', 'case'])
            ->when($tagIds !== [], fn ($query) => ($validated['match'] ?? 'any') === 'all'
                ? $query->withAllLabels($tagIds)
                : $query->withAnyLabels($tagIds))
            ->addSelect([
                'last_message_at' => Message::query()
                    ->select('created_at')
                    ->whereColumn('conversation_id', 'conversations.id')
                    ->latest()
                    ->limit(1),
            ])
            ->orderedByPinned()
            ->get();

        return ConversationResource::collection($conversations);
    }

    /**
     * Create a new conversation.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:100'],
            'case_id' => ['nullable', 'uuid', 'exists:cases,id'],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['uuid'],
        ]);

        $tags = Label::resolveForAssignment(
            $request->user(),
            $validated['label_ids'] ?? [],
            LabelKind::ThreadTag,
        );

        $case = $validated['case_id'] ?? null;

        if ($case !== null) {
            // Anyone on the case may open a thread in it, not just its owner.
            $this->authorize('update', LegalCase::findOrFail($case));
        }

        $conversation = $request->user()->conversations()->create([
            'title' => $validated['title'] ?? $validated['purpose'] ?? null,
            'purpose' => $validated['purpose'] ?? null,
            'case_id' => $case,
        ]);

        if ($tags->isNotEmpty()) {
            $conversation->syncLabels($tags, $request->user());
        }

        return (new ConversationResource($conversation->load(['labels', 'case'])))->response()->setStatusCode(201);
    }

    /**
     * Show a conversation with its messages.
     */
    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        abort_unless($conversation->isAccessibleBy($request->user()), 403);

        $conversation->load(['messages', 'labels', 'case']);

        return new ConversationResource($conversation);
    }

    /**
     * Update conversation title and tags.
     */
    public function update(Request $request, Conversation $conversation): ConversationResource
    {
        abort_unless($conversation->isAccessibleBy($request->user()), 403);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:100'],
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['uuid'],
        ]);

        if (array_key_exists('label_ids', $validated)) {
            $conversation->syncLabels(
                Label::resolveForAssignment($request->user(), $validated['label_ids'], LabelKind::ThreadTag),
                $request->user(),
            );
        }

        $conversation->update(Arr::except($validated, 'label_ids'));

        return new ConversationResource($conversation->load(['messages', 'labels', 'case']));
    }

    /**
     * Pin a conversation so it leads the thread list.
     */
    public function pin(Request $request, Conversation $conversation): ConversationResource
    {
        abort_unless($conversation->isAccessibleBy($request->user()), 403);

        $conversation->update(['pinned_at' => now()]);

        return new ConversationResource($conversation->load(['labels', 'case']));
    }

    /**
     * Unpin a conversation, letting it fall back into natural recency order.
     */
    public function unpin(Request $request, Conversation $conversation): ConversationResource
    {
        abort_unless($conversation->isAccessibleBy($request->user()), 403);

        $conversation->update(['pinned_at' => null]);

        return new ConversationResource($conversation->load(['labels', 'case']));
    }

    /**
     * Delete a conversation.
     */
    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->isAccessibleBy($request->user()), 403);

        $conversation->delete();

        return response()->json(null, 204);
    }
}
