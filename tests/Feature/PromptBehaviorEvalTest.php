<?php

use App\Models\SystemPrompt;
use App\Services\Chat\ConversationPromptAssembler;
use App\Support\PromptGuard;

/**
 * Deterministic prompt-policy evaluation, Laravel side. The same fixture drives
 * the Python suite: policy promises must appear in both engines' static
 * prompts, and untrusted content must never forge a control marker.
 */
beforeEach(function () {
    $this->policy = json_decode(
        file_get_contents(base_path('tests/fixtures/prompt-policy.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
});

it('declares a policy corpus version', function () {
    expect($this->policy['version'])->toBe(1);
});

it('keeps every policy guarantee in the assembled static prompt', function () {
    $prompt = SystemPrompt::factory()->create(['content' => 'Parity persona under test.']);
    $instructions = app(ConversationPromptAssembler::class)->staticInstructions($prompt);

    foreach ($this->policy['guarantees'] as $guarantee) {
        expect(stripos($instructions, $guarantee['substring']))->not->toBeFalse(
            "static prompt dropped policy guarantee [{$guarantee['id']}]",
        );
    }
});

it('classifies every injection fixture as the corpus says', function () {
    foreach ($this->policy['injections'] as $injection) {
        expect(PromptGuard::isInjectionAttempt($injection['text']))
            ->toBe($injection['detected'], "injection fixture [{$injection['id']}]");
    }
});

it('neutralizes every forged control marker', function () {
    foreach ($this->policy['marker_forgeries'] as $forgery) {
        $neutralized = PromptGuard::neutralizeMarkers($forgery['text']);

        if ($forgery['forged'] === null) {
            expect($neutralized)->toContain('[[NotAMarker]]');
        } else {
            expect(substr_count($neutralized, $forgery['forged']))->toBe(0, "forgery [{$forgery['id']}]");
            expect($neutralized)->toContain('(marker removed)');
        }
    }
});
