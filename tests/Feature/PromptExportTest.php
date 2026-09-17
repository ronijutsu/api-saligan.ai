<?php

use App\Models\SystemPrompt;
use Illuminate\Support\Facades\File;

/*
 * The export exists so ai-provider's ported prompt can be diffed against the
 * one Laravel composes, before precedence switches to the provider (handoff
 * §13). Without it, "byte-exact port" is a claim rather than evidence.
 */
beforeEach(function () {
    SystemPrompt::factory()->create([
        'name' => 'batayan',
        'version' => 1,
        'is_active' => true,
        'content' => 'PERSONA-SENTINEL-9f2a. You are Batayan.',
    ]);

    $this->path = storage_path('app/testing/static_instructions.txt');
});

afterEach(function () {
    File::delete($this->path);
});

it('exports the composed prompt, persona included', function () {
    $this->artisan('prompt:export', ['--path' => $this->path])->assertSuccessful();

    $exported = File::get($this->path);

    expect($exported)->toContain('PERSONA-SENTINEL-9f2a')
        ->and($exported)->toContain('[[DOCUMENT_START]]');
});

it('can export only the ported blocks, so the diff is not dominated by the persona', function () {
    $this->artisan('prompt:export', ['--path' => $this->path, '--blocks-only' => true])
        ->assertSuccessful();

    $exported = File::get($this->path);

    expect($exported)->not->toContain('PERSONA-SENTINEL-9f2a')
        ->and($exported)->toContain('[[DOCUMENT_START]]');
});
