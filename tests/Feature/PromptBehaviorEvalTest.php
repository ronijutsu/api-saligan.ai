<?php

use App\Support\PromptGuard;

/**
 * Deterministic prompt-policy evaluation, Laravel side: untrusted content
 * Laravel fences before sending it to ai-provider must never forge a control
 * marker. The prompt's guarantees and injection rules are checked by the
 * Python suite, which owns the prompt.
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
