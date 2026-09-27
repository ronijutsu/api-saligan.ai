<?php

use App\Support\ChatFrames;

/**
 * The frames Laravel writes itself must encode exactly as the shared SSE
 * corpus says, so they cannot drift from the Python encoder or the browser
 * parser. Which tool calls and results reach the wire is ai-provider's to
 * decide and test. See `contracts/sse/corpus.json`.
 */
beforeEach(function () {
    $this->corpus = json_decode(
        file_get_contents(base_path('tests/fixtures/sse-corpus.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
});

it('declares a corpus version', function () {
    expect($this->corpus['version'])->toBe(1);
});

it('encodes every frame in the corpus byte for byte', function () {
    foreach ($this->corpus['frames'] as $case) {
        expect(ChatFrames::frame($case['event'], $case['data']))->toBe($case['frame']);
    }
});
