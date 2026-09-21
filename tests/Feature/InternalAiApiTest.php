<?php

use App\Enums\MessageRole;
use App\Models\Advisory;
use App\Models\Conversation;
use App\Models\CrawledPage;
use App\Models\LegalCase;
use App\Models\LegalChunk;
use App\Models\MatterMemory;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SystemPrompt;
use App\Models\Template;
use App\Models\Todo;
use App\Models\User;
use App\Support\PlanFeatures;
use App\Support\UserProfile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config([
        'saligan.ai_provider.internal_secret' => 'test-internal-secret',
        'saligan.chat.provider' => 'ollama',
    ]);

    $this->user = User::factory()->create();
    $this->conversation = Conversation::factory()->for($this->user)->create();
    // Turn persistence settles spend, so the user needs a funded
    // subscription the way every front-door caller already does.
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
    SystemPrompt::factory()->create();
});

function internalAiPost(string $path, array $payload = []): TestResponse
{
    return test()->withToken('test-internal-secret')->postJson($path, $payload);
}

it('protects every internal route with the shared secret', function () {
    $this->getJson("/internal/conversations/{$this->conversation->id}/context")
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Invalid internal service credential.');
});

it('returns the prompt-building context', function () {
    $response = $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$this->conversation->id}/context")
        ->assertOk()
        ->assertJsonPath('user_id', $this->user->id)
        ->assertJsonPath('provider', 'ollama')
        ->assertJsonPath('messages', []);

    expect($response->getContent())->toContain('"recent_intake_values":{}');
});

it('sends plan capabilities and a bounded web-search budget', function () {
    config([
        'saligan.web_search.enabled' => true,
        'saligan.web_search.base_max_searches' => 2,
        'saligan.web_search.max_searches' => 4,
    ]);

    $basePlan = Plan::factory()->create([
        'features' => [PlanFeatures::DRAFTING],
    ]);
    Subscription::query()->where('user_id', $this->user->id)->update(['plan_id' => $basePlan->id]);

    $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$this->conversation->id}/context")
        ->assertOk()
        ->assertJsonPath('context_contract_version', 1)
        ->assertJsonPath('web_search_enabled', false)
        ->assertJsonPath('web_search_max_calls', 0)
        ->assertJsonPath('capabilities', [PlanFeatures::DRAFTING])
        ->assertJsonPath('model', config('saligan.chat.ollama_model'));

    $searchPlan = Plan::factory()->create([
        'features' => [PlanFeatures::DRAFTING, PlanFeatures::WEB_SEARCH],
    ]);
    Subscription::query()->where('user_id', $this->user->id)->update(['plan_id' => $searchPlan->id]);

    $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$this->conversation->id}/context")
        ->assertOk()
        ->assertJsonPath('web_search_enabled', true)
        ->assertJsonPath('web_search_max_calls', 2)
        ->assertJsonPath('capabilities', [PlanFeatures::DRAFTING, PlanFeatures::WEB_SEARCH]);

    $deepResearchPlan = Plan::factory()->create([
        'features' => [PlanFeatures::DRAFTING, PlanFeatures::WEB_SEARCH, PlanFeatures::DEEP_RESEARCH],
    ]);
    Subscription::query()->where('user_id', $this->user->id)->update(['plan_id' => $deepResearchPlan->id]);

    $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$this->conversation->id}/context")
        ->assertOk()
        ->assertJsonPath('deep_research', true)
        ->assertJsonPath('web_search_enabled', true)
        ->assertJsonPath('web_search_max_calls', 4)
        ->assertJsonPath('capabilities', [PlanFeatures::DRAFTING, PlanFeatures::WEB_SEARCH, PlanFeatures::DEEP_RESEARCH]);

    config(['saligan.web_search.enabled' => false]);

    $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$this->conversation->id}/context")
        ->assertOk()
        ->assertJsonPath('web_search_enabled', false)
        ->assertJsonPath('web_search_max_calls', 0);
});

it('uses an explicit visible template instead of the case default', function () {
    $default = Template::factory()->system()->create([
        'name' => 'Case default',
        'category' => 'formal',
    ]);
    $explicit = Template::factory()->system()->create([
        'name' => 'Selected template',
        'category' => 'legal',
        'legal_subtype' => 'notice_to_explain',
        'content' => 'Selected template content.',
        'structure' => ['Heading', 'Facts', 'Request'],
        'placeholder_fields' => [['key' => 'recipient_name', 'label' => 'Recipient']],
    ]);
    $case = LegalCase::factory()->for($this->user)->create([
        'default_template_id' => $default->id,
    ]);
    $conversation = Conversation::factory()->for($this->user)->create(['case_id' => $case->id]);

    $response = $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$conversation->id}/context?current_message=".urlencode("[Template: {$explicit->id}]\nDraft this document."))
        ->assertOk()
        ->assertJsonPath('resolved_template.id', $explicit->id)
        ->assertJsonPath('resolved_template.mode', 'structured')
        ->assertJsonPath('resolved_template.name', 'Selected template')
        ->assertJsonPath('resolved_template.category', 'legal')
        ->assertJsonPath('resolved_template.legal_subtype', 'notice_to_explain')
        ->assertJsonPath('resolved_template.content', 'Selected template content.')
        ->assertJsonPath('resolved_template.structure', ['Heading', 'Facts', 'Request'])
        ->assertJsonPath('resolved_template.placeholder_fields', [['key' => 'recipient_name', 'label' => 'Recipient']]);

    expect($response->json('template'))
        ->toContain('Selected template')
        ->not->toContain('Case default');
});

it('does not expose an invisible explicit template or fall back to the case default', function () {
    $default = Template::factory()->system()->create(['name' => 'Case default']);
    $invisible = Template::factory()->for(User::factory())->create(['name' => 'Private template']);
    $case = LegalCase::factory()->for($this->user)->create([
        'default_template_id' => $default->id,
    ]);
    $conversation = Conversation::factory()->for($this->user)->create(['case_id' => $case->id]);

    $response = $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$conversation->id}/context?current_message=".urlencode("[Template: {$invisible->id}]\nDraft this document."))
        ->assertOk();

    expect($response->json('resolved_template'))->toBeNull()
        ->and($response->json('template'))->toBe('');
});

it('marks an uploaded template with placeholders as verbatim', function () {
    $template = Template::factory()->create([
        'name' => 'Uploaded letterhead',
        'original_path' => 'templates/uploaded-letterhead.docx',
        'placeholder_fields' => ['recipient_name'],
    ]);

    $response = $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$this->conversation->id}/context?current_message=".urlencode("[Template: {$template->id}]\nFill this template."))
        ->assertOk();

    expect($response->json('resolved_template'))
        ->toMatchArray([
            'id' => $template->id,
            'mode' => 'verbatim',
            'name' => 'Uploaded letterhead',
        ]);
});

it('round-trips verbatim template fields with the template identity', function () {
    $template = Template::factory()->create([
        'user_id' => $this->user->id,
        'original_path' => 'templates/letterhead.docx',
        'placeholder_fields' => ['[Recipient]'],
    ]);

    internalAiPost("/internal/conversations/{$this->conversation->id}/letters", [
        'title' => 'Filled letterhead',
        'template_id' => $template->id,
        'template_fields' => ['[Recipient]' => 'Juan Dela Cruz'],
        'tool_call_id' => 'fill-template-1',
    ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('template_id', $template->id)
        ->assertJsonPath('template_fields.[Recipient]', 'Juan Dela Cruz');
});

it('rejects a template callback for an inaccessible or non-verbatim template', function () {
    $template = Template::factory()->system()->create([
        'name' => 'Structured only',
        'placeholder_fields' => [],
    ]);

    internalAiPost("/internal/conversations/{$this->conversation->id}/letters", [
        'title' => 'Invalid fill',
        'template_id' => $template->id,
        'template_fields' => ['[Recipient]' => 'Someone'],
    ])->assertUnprocessable();
});

it('identifies the canonical active system prompt in the context', function () {
    $prompt = SystemPrompt::factory()->create([
        'name' => 'batayan',
        'version' => 7,
        'content' => 'The canonical Batayan prompt.',
    ]);

    $response = $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$this->conversation->id}/context")
        ->assertOk()
        ->assertJsonPath('system_prompt.id', $prompt->id)
        ->assertJsonPath('system_prompt.version', 7)
        ->assertJsonPath('system_prompt.content', 'The canonical Batayan prompt.');

    expect($response->json('persistence'))
        ->toMatchArray([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'case_id' => null,
        ]);
});

it('validates the optional current message before building context', function () {
    $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$this->conversation->id}/context?current_message=".urlencode(str_repeat('x', 8001)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_message']);
});

it('returns onboarding profile calibration in the prompt-building context', function () {
    $this->user->forceFill([
        'kyc_role' => UserProfile::ROLE_BUSINESS_OWNER,
        'kyc_use_case' => UserProfile::USE_CASE_CLIENT_WORK,
        'kyc_experience_level' => UserProfile::EXP_PROFESSIONAL,
        'kyc_completed_at' => now(),
    ])->save();

    $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$this->conversation->id}/context")
        ->assertOk()
        ->assertJsonPath('user_profile', fn (string $profile): bool => str_contains($profile, 'Role: Business Owner / Entrepreneur')
            && str_contains($profile, 'Primary use: Preparing documents/research for clients')
            && str_contains($profile, 'Experience level: Professional'));
});

it('omits onboarding calibration when the profile was not completed', function () {
    $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$this->conversation->id}/context")
        ->assertOk()
        ->assertJsonPath('user_profile', '');
});

it('persists a completed turn idempotently', function () {
    $messageId = (string) Str::uuid();
    $payload = [
        'message_id' => $messageId,
        'provider' => 'gemini',
        'user' => ['content' => 'What does the law say?', 'attachment_ids' => []],
        'assistant' => ['content' => 'It depends on the governing statute.'],
        'metadata' => [
            'activity' => [['status' => 'composing', 'label' => 'Writing your answer']],
            'legal_chunk_ids' => [(string) Str::uuid()],
            'document_chunk_ids' => [(string) Str::uuid()],
            'web_citations' => [],
        ],
    ];

    internalAiPost("/internal/conversations/{$this->conversation->id}/messages", $payload)
        ->assertOk()
        ->assertJsonPath('idempotent', false)
        ->assertJsonPath('message_id', $messageId);

    internalAiPost("/internal/conversations/{$this->conversation->id}/messages", $payload)
        ->assertOk()
        ->assertJsonPath('idempotent', true);

    expect(Message::where('conversation_id', $this->conversation->id)->count())->toBe(2);
    $assistant = Message::findOrFail($messageId);

    expect($assistant->role)->toBe(MessageRole::Assistant)
        ->and($assistant->provider->value)->toBe('gemini')
        ->and($assistant->metadata['activity'][0]['status'])->toBe('composing');
});

it('does not treat a message id from another conversation as idempotent', function () {
    $messageId = (string) Str::uuid();
    $otherConversation = Conversation::factory()->for($this->user)->create();

    internalAiPost("/internal/conversations/{$this->conversation->id}/messages", [
        'message_id' => $messageId,
        'user' => ['content' => 'First turn', 'attachment_ids' => []],
        'assistant' => ['content' => 'First answer.'],
    ])->assertOk();

    internalAiPost("/internal/conversations/{$otherConversation->id}/messages", [
        'message_id' => $messageId,
        'user' => ['content' => 'Second turn', 'attachment_ids' => []],
        'assistant' => ['content' => 'Second answer.'],
    ])->assertStatus(409);
});

it('does not treat a user message id as an idempotent assistant callback', function () {
    $userMessage = Message::factory()->for($this->conversation)->create([
        'role' => MessageRole::User,
        'content' => 'Already persisted user turn.',
    ]);

    internalAiPost("/internal/conversations/{$this->conversation->id}/messages", [
        'message_id' => $userMessage->id,
        'user' => ['content' => 'Retrying the turn', 'attachment_ids' => []],
        'assistant' => ['content' => 'This must not overwrite a user message.'],
    ])->assertStatus(409);
});

it('forwards Meta to the Python service when configured', function () {
    // Meta is served through its OpenAI-compatible endpoint by the Python
    // adapter, so the context must preserve the provider and configured model.
    config([
        'saligan.chat.provider' => 'meta',
        'ai.providers.meta.key' => 'test-meta-key',
        'ai.providers.gemini.key' => 'test-gemini-key',
    ]);

    $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$this->conversation->id}/context")
        ->assertOk()
        ->assertJsonPath('provider', 'meta')
        ->assertJsonPath('model', config('saligan.chat.meta_model'));
});

it('forwards OpenRouter to the Python service rather than mapping it', function () {
    // OpenRouter is served through the OpenAI-compatible client, so the context
    // builder passes it straight through: what the operator configured is what
    // answers, and the free-only default model is what keeps it zero-cost.
    config([
        'saligan.chat.provider' => 'openrouter',
        'ai.providers.openrouter.key' => 'test-openrouter-key',
        'saligan.chat.openrouter_model' => 'openrouter/free',
    ]);

    $this->withToken('test-internal-secret')
        ->getJson("/internal/conversations/{$this->conversation->id}/context")
        ->assertOk()
        ->assertJsonPath('provider', 'openrouter')
        ->assertJsonPath('model', 'openrouter/free');
});

it('persists the turn usage the provider reports', function () {
    $messageId = (string) Str::uuid();

    internalAiPost("/internal/conversations/{$this->conversation->id}/messages", [
        'message_id' => $messageId,
        'provider' => 'gemini',
        'user' => ['content' => 'What does the law say?', 'attachment_ids' => []],
        'assistant' => ['content' => 'It depends on the governing statute.'],
        'metadata' => [
            'activity' => [['status' => 'composing']],
            'usage' => [
                'provider' => 'gemini',
                'model' => 'gemini-3.7-flash',
                'input_tokens' => 1000,
                'output_tokens' => 100,
                'cache_read_tokens' => 800,
                'cache_write_tokens' => 0,
                'searches' => 1,
            ],
        ],
    ])->assertOk();

    $assistant = Message::findOrFail($messageId);

    expect($assistant->metadata['usage']['model'])->toBe('gemini-3.7-flash')
        ->and($assistant->metadata['usage']['input_tokens'])->toBe(1000)
        ->and($assistant->metadata['usage']['searches'])->toBe(1);
});

it('persists standard chunk ids separately from generic callback metadata', function () {
    $page = CrawledPage::factory()->standard()->create();
    $chunk = LegalChunk::factory()->for($page)->create();
    $messageId = (string) Str::uuid();

    internalAiPost("/internal/conversations/{$this->conversation->id}/messages", [
        'message_id' => $messageId,
        'user' => ['content' => 'What does the standard define?', 'attachment_ids' => []],
        'assistant' => ['content' => 'It defines a message format.'],
        'metadata' => [
            'standard_chunk_ids' => [$chunk->id],
            'activity' => [['status' => 'composing']],
        ],
    ])->assertOk();

    $assistant = Message::findOrFail($messageId);

    expect($assistant->cited_standard_chunk_ids)->toBe([$chunk->id])
        ->and($assistant->metadata)->not->toHaveKey('standard_chunk_ids')
        ->and($assistant->metadata['activity'])->toHaveCount(1);
});

it('creates todos and advisories idempotently by tool call id', function () {
    $todoPayload = [
        'tool_call_id' => 'todo-call-1',
        'items' => [['title' => 'File the complaint', 'status' => 'pending']],
    ];
    $advisoryPayload = [
        'tool_call_id' => 'advisory-call-1',
        'items' => [[
            'kind' => 'deadline',
            'title' => 'The filing period may expire this month',
            'detail' => 'Confirm the date of receipt.',
            'severity' => 'high',
        ]],
    ];

    foreach ([1, 2] as $_) {
        internalAiPost("/internal/conversations/{$this->conversation->id}/todos", $todoPayload)
            ->assertOk()
            ->assertJsonPath('accepted', 1);
        internalAiPost("/internal/conversations/{$this->conversation->id}/advisories", $advisoryPayload)
            ->assertOk()
            ->assertJsonPath('accepted', 1);
    }

    expect(Todo::where('conversation_id', $this->conversation->id)->count())->toBe(1)
        ->and(Advisory::where('conversation_id', $this->conversation->id)->count())->toBe(1);
});

it('normalizes letter callback payloads', function () {
    internalAiPost("/internal/conversations/{$this->conversation->id}/letters", [
        'title' => 'Demand Letter',
        'content' => [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => []]],
        ],
        'tool_call_id' => 'letter-call-1',
    ])->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('title', 'Demand Letter')
        ->assertJsonPath('content.type', 'doc');
});

it('stores matter memory through the existing domain service', function () {
    $case = LegalCase::factory()->for($this->user)->create();
    $conversation = Conversation::factory()->for($this->user)->create(['case_id' => $case->id]);

    internalAiPost("/internal/conversations/{$conversation->id}/memory", [
        'facts' => ["matter={$case->id} type=fact content: The client received notice on 1 August."],
        'tool_call_id' => 'memory-call-1',
    ])->assertOk()
        ->assertJsonPath('accepted', 1);

    expect(MatterMemory::where('case_id', $case->id)->count())->toBe(1);
});
