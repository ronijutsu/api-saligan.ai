<?php

use App\Support\ChatFrames;

/**
 * The Laravel relay must emit exactly what the shared SSE corpus says, so it
 * cannot drift from the Python encoder or the browser parser. See
 * `contracts/sse/corpus.json`.
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

it('exposes only the tool calls the corpus allows', function () {
    foreach ($this->corpus['tool_calls'] as $case) {
        expect(ChatFrames::call($case['tool'], $case['arguments']))->toBe($case['rendered']);
    }
});

it('reduces every tool result exactly as the corpus says', function () {
    foreach ($this->corpus['tool_results'] as $case) {
        expect(ChatFrames::result($case['tool'], $case['result']))->toBe($case['rendered']);
    }
});

it('never emits a success receipt for a failed tool', function () {
    expect(ChatFrames::result('create_todo', '{"ok":false,"items":[{"id":"1"}]}'))->toBeNull();
    expect(ChatFrames::result('flag_advisories', '{"ok":false,"items":[]}'))->toBeNull();
});
