<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'saligan.ai_provider.url' => 'http://ai-provider.test',
        'saligan.ai_provider.internal_secret' => 'shared-secret',
    ]);

    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

it('records the attached documents on the user message', function () {
    $document = Document::factory()->for($this->user)->ready()->create();
    $conversation = Conversation::factory()->for($this->user)->create();

    $this->withToken('shared-secret')
        ->postJson("/internal/conversations/{$conversation->id}/messages", [
            'message_id' => (string) Str::uuid(),
            'user' => ['content' => 'What does this contract say?', 'attachment_ids' => [$document->id]],
            'assistant' => ['content' => 'It is a lease.'],
        ])
        ->assertOk();

    $userMessage = Message::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', MessageRole::User)
        ->firstOrFail();

    expect($userMessage->metadata['attachment_ids'])->toBe([$document->id]);
});

it('drops attachment ids that do not belong to the sender', function () {
    Http::preventStrayRequests();
    Http::fake(['ai-provider.test/*' => Http::response("event: done\ndata: {\"ok\":true,\"web_citations\":0}\n\n")]);

    $mine = Document::factory()->for($this->user)->ready()->create();
    $theirs = Document::factory()->for(User::factory()->create())->ready()->create();

    $conversation = Conversation::factory()->for($this->user)->create();

    $this->signInAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Review these, please.',
            'attachment_ids' => [$mine->id, $theirs->id],
        ])
        ->assertOk()
        ->streamedContent();

    Http::assertSent(fn (Request $request): bool => $request['attachment_ids'] === [$mine->id]);
});

it('returns the attachments of a message with the conversation', function () {
    $document = Document::factory()->for($this->user)->ready()->create([
        'original_filename' => 'lease_agreement_2024.pdf',
    ]);

    $conversation = Conversation::factory()->for($this->user)->create();

    Message::create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::User,
        'content' => 'What does this lease say about renewal?',
        'metadata' => ['attachment_ids' => [$document->id]],
    ]);

    $this->signInAs($this->user)
        ->getJson("/api/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonPath('data.messages.0.attachments.0.id', $document->id)
        ->assertJsonPath('data.messages.0.attachments.0.original_filename', 'lease_agreement_2024.pdf')
        ->assertJsonPath('data.messages.0.attachments.0.status', 'ready');
});

it('omits an attachment whose document has since been deleted', function () {
    $document = Document::factory()->for($this->user)->ready()->create();
    $conversation = Conversation::factory()->for($this->user)->create();

    Message::create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::User,
        'content' => 'Check this.',
        'metadata' => ['attachment_ids' => [$document->id]],
    ]);

    $document->delete();

    $this->signInAs($this->user)
        ->getJson("/api/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonPath('data.messages.0.attachments', []);
});
