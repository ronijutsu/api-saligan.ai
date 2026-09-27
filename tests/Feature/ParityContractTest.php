<?php

use App\Models\Conversation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SystemPrompt;
use App\Models\Template;
use App\Models\User;

/**
 * Parity, Laravel side: resolve what each shared scenario's plan buys and
 * prove the wire matches the corpus the Python suite consumes. The model and
 * the deployment's search switch are ai-provider's side of the same corpus.
 * See `contracts/parity/scenarios.json`.
 */
beforeEach(function () {
    config([
        'saligan.ai_provider.internal_secret' => 'parity-secret',
        'saligan.web_search.base_max_searches' => 2,
        'saligan.web_search.max_searches' => 4,
    ]);

    SystemPrompt::factory()->create();

    $this->corpus = json_decode(
        file_get_contents(base_path('tests/fixtures/parity-scenarios.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
});

it('declares a parity corpus version', function () {
    expect($this->corpus['version'])->toBe(2);
});

foreach (json_decode(file_get_contents(__DIR__.'/../fixtures/parity-scenarios.json'), true)['scenarios'] as $scenario) {
    it("resolves the authoritative context for {$scenario['id']}", function () use ($scenario) {
        $setup = $scenario['setup'];
        $expected = $scenario['expected_context'];

        $user = User::factory()->create();
        $plan = Plan::factory()->create(['features' => $setup['plan_features']]);
        Subscription::factory()->for($user)->create(['plan_id' => $plan->id]);
        $conversation = Conversation::factory()->for($user)->create();

        $query = '';

        if (($setup['template']['explicit'] ?? false) === true) {
            $template = $setup['template']['mode'] === 'verbatim'
                ? Template::factory()->system()->create([
                    'name' => 'Parity verbatim',
                    'category' => 'formal',
                    'original_path' => 'templates/parity-verbatim.docx',
                    'placeholder_fields' => ['[Name]'],
                ])
                : Template::factory()->system()->create([
                    'name' => 'Parity structured',
                    'category' => 'formal',
                    'placeholder_fields' => [],
                ]);

            $this->templateId = $template->id;
            $query = '?current_message='.urlencode("[Template: {$template->id}]\nDraft this document.");
        }

        $response = $this->withToken('parity-secret')
            ->getJson("/internal/conversations/{$conversation->id}/context{$query}")
            ->assertOk()
            ->assertJsonPath('capabilities', $expected['capabilities'])
            ->assertJsonPath('deep_research', $expected['deep_research'])
            ->assertJsonPath('web_search_enabled', $expected['web_search_enabled'])
            ->assertJsonPath('web_search_max_calls', $expected['web_search_max_calls'])
            ->assertJsonPath('template_mode', $expected['template_mode'])
            ->assertJsonPath('context_contract_version', 2)
            ->assertJsonMissingPath('provider')
            ->assertJsonMissingPath('model');

        if ($expected['resolved_template'] === null) {
            $response->assertJsonPath('resolved_template', null);
        } else {
            $response
                ->assertJsonPath('resolved_template.mode', $expected['resolved_template']['mode'])
                ->assertJsonPath('resolved_template.id', $this->templateId);
        }

        if ($expected['has_prompt_identity']) {
            expect($response->json('system_prompt.id'))->not->toBeNull()
                ->and($response->json('system_prompt.version'))->toBeInt();
        }
    });
}
