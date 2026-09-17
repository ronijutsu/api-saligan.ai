<?php

use App\Ai\LegalChatAgent;
use App\Models\Conversation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SystemPrompt;
use App\Models\User;
use App\Services\Chat\ChatService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Pinned rather than left to the default: GEMINI_CHAT_MODEL is set in most
    // real .env files, and a test that asserts on the model must not depend on
    // whose machine it runs on.
    config(['saligan.chat.gemini_model' => 'gemini-3.6-flash']);

    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);

    SystemPrompt::factory()->create([
        'name' => 'saligan',
        'content' => 'You are Saligan, a Philippine legal research assistant.',
        'version' => 1,
        'is_active' => true,
    ]);

    config(['ai.providers.gemini.key' => 'gemini-key']);
    config(['ai.providers.gemini.url' => 'https://generativelanguage.googleapis.com/v1beta/']);
    config(['saligan.context_caching.enabled' => true]);

    Cache::flush();

    LegalChatAgent::fake();
});

function geminiRequests(): Collection
{
    return collect(Http::recorded())->filter(
        fn ($pair) => str_contains($pair[0]->url(), 'generativelanguage.googleapis.com')
    );
}

it('creates a Gemini context cache when streaming on the Gemini provider', function () {
    config(['saligan.chat.provider' => 'gemini']);

    Http::fake([
        '*/embeddings' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
        'generativelanguage.googleapis.com/*' => Http::response([
            'name' => 'cachedContents/abc123',
        ]),
    ]);

    $conversation = Conversation::factory()->for($this->user)->create();

    $stream = app(ChatService::class)->stream($conversation, 'What is RA 6657?');

    foreach ($stream as $event) {
        // Consume the stream so callbacks run.
    }

    $cachedContents = geminiRequests()->filter(
        fn ($pair) => str_ends_with($pair[0]->url(), '/cachedContents')
    );

    expect($cachedContents)->toHaveCount(1)
        ->and(data_get($cachedContents->first()[0]->data(), 'model'))->toBe('models/gemini-3.6-flash');
});

it('does not create a context cache when streaming on Ollama', function () {
    Http::fake([
        '*/embeddings' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
        'generativelanguage.googleapis.com/*' => Http::response([
            'name' => 'cachedContents/abc123',
        ]),
    ]);

    $conversation = Conversation::factory()->for($this->user)->create();

    $stream = app(ChatService::class)->stream($conversation, 'What is RA 6657?');

    foreach ($stream as $event) {
        // Consume the stream so callbacks run.
    }

    expect(geminiRequests())->toBeEmpty();
});

it('persists the native engine and active prompt identity with the reply', function () {
    config([
        'saligan.chat.engine' => 'laravel',
        'saligan.chat.provider' => 'ollama',
    ]);

    Http::fake([
        '*/embeddings' => Http::response(['embeddings' => [array_fill(0, 768, 1.0)]], 200),
    ]);

    $conversation = Conversation::factory()->for($this->user)->create();
    $prompt = SystemPrompt::query()->where('name', 'saligan')->firstOrFail();

    foreach (app(ChatService::class)->stream($conversation, 'What is RA 6657?') as $event) {
        // Consume the stream so the completion callback persists the reply.
    }

    $assistant = $conversation->messages()->where('role', 'assistant')->firstOrFail();

    expect($assistant->metadata)->toMatchArray([
        'engine' => 'laravel',
        'prompt' => [
            'id' => (string) $prompt->id,
            'version' => 1,
        ],
    ]);
});
