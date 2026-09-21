<?php

use App\Ai\LegalChatAgent;
use App\Enums\ChatProvider;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SystemPrompt;
use App\Models\User;
use App\Services\Chat\ChatService;
use Generator;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

/**
 * A chat service that records the attachment ids it was handed, so the
 * controller's filtering can be asserted without running a real stream.
 */
function fakeChatServiceRecordingAttachments(): ChatService
{
    return new class extends ChatService
    {
        /** @var array<int, string> */
        public static array $received = [];

        public function __construct() {}

        public function stream(Conversation $conversation, string $question, ?callable $onStatus = null, array $attachmentIds = [], ?callable $onWebSearch = null): StreamableAgentResponse
        {
            self::$received = $attachmentIds;

            return new StreamableAgentResponse('test-invocation', function (): Generator {
                yield new TextDelta(id: 'a', messageId: 'm1', delta: 'Noted.', timestamp: 1);
                yield new StreamEnd(id: 'b', reason: 'stop', usage: new Usage(promptTokens: 1, completionTokens: 1), timestamp: 2);
            }, new Meta(provider: 'ollama', model: 'test-model'));
        }
    };
}

it('records the attached documents on the user message', function () {
    SystemPrompt::factory()->create([
        'name' => 'saligan',
        'content' => 'You are Saligan, a Philippine legal research assistant.',
        'version' => 1,
        'is_active' => true,
    ]);

    Http::fake([
        '*/api/embed' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
        // The chat turn embeds its query through the Python provider, so that
        // endpoint has to be stubbed too; unmatched requests otherwise reach
        // the network and fail on an unresolvable host.
        '*/embeddings' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
    ]);

    LegalChatAgent::fake();

    $document = Document::factory()->for($this->user)->ready()->create();

    $conversation = Conversation::factory()->for($this->user)->create([
        'provider' => ChatProvider::Ollama,
    ]);

    $stream = app(ChatService::class)->stream($conversation, 'What does this contract say?', null, [$document->id]);

    foreach ($stream as $event) {
        // Consume the stream so callbacks run.
    }

    $userMessage = Message::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', MessageRole::User)
        ->firstOrFail();

    expect($userMessage->metadata['attachment_ids'])->toBe([$document->id]);
});

it('drops attachment ids that do not belong to the sender', function () {
    // This contract exercises the Laravel controller's native branch. The
    // application defaults to the Python engine, so make the harness explicit
    // before replacing the native ChatService with the recording double.
    config(['saligan.chat.engine' => 'laravel']);

    $fake = fakeChatServiceRecordingAttachments();
    $this->app->instance(ChatService::class, $fake);

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

    expect($fake::$received)->toBe([$mine->id]);
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
