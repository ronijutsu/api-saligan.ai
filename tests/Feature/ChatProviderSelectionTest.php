<?php

use App\Enums\ChatProvider;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Support\PlanFeatures;
use Laravel\Ai\Enums\Lab;

beforeEach(function () {
    $this->chat = new class extends ChatService
    {
        public function __construct() {}

        public function resolveFor(Conversation $conversation): array
        {
            return $this->resolveProvider($conversation);
        }
    };
});

it('derives the default provider from configuration', function () {
    config()->set('saligan.chat.provider', 'gemini');
    expect(ChatProvider::fromConfig())->toBe(ChatProvider::Gemini);

    config()->set('saligan.chat.provider', 'unknown');
    expect(ChatProvider::fromConfig())->toBe(ChatProvider::Ollama);
});

it('derives Anthropic as the default provider from configuration', function () {
    config()->set('saligan.chat.provider', 'anthropic');
    expect(ChatProvider::fromConfig())->toBe(ChatProvider::Anthropic);
});

it('derives Meta as the default provider from configuration', function () {
    config()->set('saligan.chat.provider', 'meta');
    expect(ChatProvider::fromConfig())->toBe(ChatProvider::Meta);
});

it('resolves the configured default to Ollama when the provider is unknown', function () {
    config()->set('saligan.chat.provider', 'unknown');

    $conversation = Conversation::factory()->create();

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Ollama,
        config('saligan.chat.ollama_model'),
    ]);
});

it('uses the configured default rather than the provider stored on the conversation', function () {
    config()->set('saligan.chat.provider', 'meta');
    config()->set('ai.providers.meta.key', 'test-key');

    $conversation = Conversation::factory()->create([
        'provider' => ChatProvider::Ollama,
    ]);

    expect($this->chat->resolveFor($conversation))->toBe([
        'meta',
        config('saligan.chat.meta_model'),
    ]);
});

it('uses Gemini when Gemini is the configured default and an API key is set', function () {
    config()->set('saligan.chat.provider', 'gemini');
    config()->set('ai.providers.gemini.key', 'test-key');

    $conversation = Conversation::factory()->create();

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Gemini,
        config('saligan.chat.gemini_model'),
    ]);
});

it('falls back to Ollama when Gemini is the configured default but no API key is set', function () {
    config()->set('saligan.chat.provider', 'gemini');
    config()->set('ai.providers.gemini.key', '');

    $conversation = Conversation::factory()->create();

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Ollama,
        config('saligan.chat.ollama_model'),
    ]);
});

it('uses OpenAI when OpenAI is the configured default and an API key is set', function () {
    config()->set('saligan.chat.provider', 'openai');
    config()->set('ai.providers.openai.key', 'test-key');

    $conversation = Conversation::factory()->create();

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::OpenAI,
        config('saligan.chat.openai_model'),
    ]);
});

it('falls back to Ollama when OpenAI is the configured default but no API key is set', function () {
    config()->set('saligan.chat.provider', 'openai');
    config()->set('ai.providers.openai.key', '');

    $conversation = Conversation::factory()->create();

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Ollama,
        config('saligan.chat.ollama_model'),
    ]);
});

it('uses Meta when Meta is the configured default and an API key is set', function () {
    config()->set('saligan.chat.provider', 'meta');
    config()->set('ai.providers.meta.key', 'test-key');

    $conversation = Conversation::factory()->create();

    expect($this->chat->resolveFor($conversation))->toBe([
        'meta',
        config('saligan.chat.meta_model'),
    ]);
});

it('falls back to Ollama when Meta is the configured default but no API key is set', function () {
    config()->set('saligan.chat.provider', 'meta');
    config()->set('ai.providers.meta.key', '');

    $conversation = Conversation::factory()->create();

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Ollama,
        config('saligan.chat.ollama_model'),
    ]);
});

it('falls back to Gemini when Anthropic is the configured default but no API key is set', function () {
    config()->set('saligan.chat.provider', 'anthropic');
    config()->set('ai.providers.anthropic.key', '');

    $conversation = Conversation::factory()->create();

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Gemini,
        config('saligan.chat.gemini_model'),
    ]);
});

it('uses Anthropic when Anthropic is the configured default and an API key is set', function () {
    config()->set('saligan.chat.provider', 'anthropic');
    config()->set('ai.providers.anthropic.key', 'test-key');

    // On a plan that carries the frontier model, so this asserts the provider
    // choice without the model choice riding along on it.
    $conversation = anthropicConversationFor([
        'status' => Subscription::STATUS_ACTIVE,
        'plan_id' => Plan::factory()->pro(),
    ]);

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Anthropic,
        config('saligan.chat.anthropic_model'),
    ]);
});

/**
 * An Anthropic conversation owned by a user whose organization is on a
 * subscription in the given state, on a plan with the given features.
 *
 * @param  list<string>|null  $features  Null leaves the factory default, which
 *                                       carries every capability.
 */
function anthropicConversationFor(array $subscription, ?array $features = null): Conversation
{
    $organization = Organization::factory()->create();

    Subscription::factory()->create([
        ...$subscription,
        'organization_id' => $organization->id,
        ...($features === null ? [] : ['plan_id' => Plan::factory()->create(['features' => $features])]),
    ]);

    return Conversation::factory()->create([
        'provider' => ChatProvider::Anthropic,
        'user_id' => User::factory()->memberOf($organization),
    ]);
}

/*
 * The frontier model is a plan feature. What is being switched is cost, not
 * access: both models answer from the same retrieved sources.
 */
it('serves the base model to a plan without the frontier model feature', function () {
    config()->set('saligan.chat.provider', 'anthropic');
    config()->set('ai.providers.anthropic.key', 'test-key');

    $conversation = anthropicConversationFor(
        ['status' => Subscription::STATUS_ACTIVE],
        features: [PlanFeatures::DRAFTING, PlanFeatures::EXPORTS],
    );

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Anthropic,
        config('saligan.chat.anthropic_base_model'),
    ]);
});

/*
 * The trial is not special-cased any more — it is simply a plan that does not
 * carry the feature, and it lands on the base model for that reason alone.
 */
it('serves the base model to an organization still inside its trial', function () {
    config()->set('saligan.chat.provider', 'anthropic');
    config()->set('ai.providers.anthropic.key', 'test-key');

    $conversation = anthropicConversationFor([
        'status' => Subscription::STATUS_TRIALING,
        'trial_ends_at' => now()->addDays(7),
        'plan_id' => Plan::factory()->trial(),
    ]);

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Anthropic,
        config('saligan.chat.anthropic_base_model'),
    ]);
});

it('serves the frontier model to a plan that carries it', function () {
    config()->set('saligan.chat.provider', 'anthropic');
    config()->set('ai.providers.anthropic.key', 'test-key');

    $conversation = anthropicConversationFor([
        'status' => Subscription::STATUS_ACTIVE,
        'plan_id' => Plan::factory()->pro(),
    ]);

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Anthropic,
        config('saligan.chat.anthropic_model'),
    ]);
});

/*
 * A lapsed trial is not a trial. Such a user has no access at all and never
 * reaches a chat turn, but the model choice must follow the plan they are on
 * rather than the status of the row.
 */
it('follows the plan once the trial has lapsed', function () {
    config()->set('saligan.chat.provider', 'anthropic');
    config()->set('ai.providers.anthropic.key', 'test-key');

    $conversation = anthropicConversationFor([
        'status' => Subscription::STATUS_TRIALING,
        'trial_ends_at' => now()->subDay(),
        'plan_id' => Plan::factory()->pro(),
    ]);

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Anthropic,
        config('saligan.chat.anthropic_model'),
    ]);
});

it('serves the frontier model to everyone when no base model is configured', function () {
    config()->set('saligan.chat.provider', 'anthropic');
    config()->set('ai.providers.anthropic.key', 'test-key');
    config()->set('saligan.chat.anthropic_base_model', null);

    $conversation = anthropicConversationFor([
        'status' => Subscription::STATUS_TRIALING,
        'trial_ends_at' => now()->addDays(7),
        'plan_id' => Plan::factory()->trial(),
    ]);

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Anthropic,
        config('saligan.chat.anthropic_model'),
    ]);
});

it('derives OpenRouter as the default provider from configuration', function () {
    config()->set('saligan.chat.provider', 'openrouter');
    expect(ChatProvider::fromConfig())->toBe(ChatProvider::Openrouter);
});

it('uses OpenRouter when OpenRouter is the configured default and an API key is set', function () {
    config()->set('saligan.chat.provider', 'openrouter');
    config()->set('ai.providers.openrouter.key', 'test-key');

    $conversation = Conversation::factory()->create();

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::OpenRouter,
        config('saligan.chat.openrouter_model'),
    ]);
});

it('falls back to Ollama when OpenRouter is the configured default but no API key is set', function () {
    config()->set('saligan.chat.provider', 'openrouter');
    config()->set('ai.providers.openrouter.key', '');

    $conversation = Conversation::factory()->create();

    expect($this->chat->resolveFor($conversation))->toBe([
        Lab::Ollama,
        config('saligan.chat.ollama_model'),
    ]);
});
