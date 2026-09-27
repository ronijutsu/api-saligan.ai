<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

it('rewrites a passage through the agent and returns the plain text', function () {
    fakeRewrite('We regret to inform you that your request has been denied.');

    $response = $this->signInAs($this->user)
        ->postJson('/api/text/rewrite', [
            'text' => 'We regret to inform you that your request has been denied.',
            'instruction' => 'Make this friendlier.',
        ])
        ->assertOk();

    expect($response->json('data.text'))
        ->toBe('We regret to inform you that your request has been denied.');
});

it('unwraps a json-wrapped reply from the provider', function () {
    fakeRewrite('"Kindly let us know how we may help you."');

    $response = $this->signInAs($this->user)
        ->postJson('/api/text/rewrite', [
            'text' => 'Let us know how we may help you.',
            'instruction' => 'Make this formal.',
        ])
        ->assertOk();

    expect($response->json('data.text'))->toBe('Kindly let us know how we may help you.');
});

it('reports a failure rather than echoing the passage back when every attempt is empty', function () {
    // Handing the original back reaches the editor as a suggestion identical
    // to what is already on screen — indistinguishable, to the reader, from a
    // rewrite that decided no change was needed.
    fakeRewrite('', '', '');

    $this->signInAs($this->user)
        ->postJson('/api/text/rewrite', [
            'text' => 'Original passage.',
            'instruction' => 'Rewrite it.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The assistant could not rewrite that passage. Please try again.');
});

it('treats a structurally empty JSON reply as no answer at all', function () {
    // The Ollama path used to be given `format: json` against a prompt asking
    // for plain text; the models resolved that by answering `{}`, and the
    // extractor passed the literal braces through into the user's letter.
    fakeRewrite('{}', '   ', 'null');

    $this->signInAs($this->user)
        ->postJson('/api/text/rewrite', [
            'text' => 'Original passage.',
            'instruction' => 'Fix the grammar.',
        ])
        ->assertStatus(422);
});

it('recovers the passage from a JSON object that does carry one', function () {
    fakeRewrite('{"text": "The corrected passage."}');

    $response = $this->signInAs($this->user)
        ->postJson('/api/text/rewrite', [
            'text' => 'Original passage.',
            'instruction' => 'Fix the grammar.',
        ])
        ->assertOk();

    expect($response->json('data.text'))->toBe('The corrected passage.');
});

it('strips a code fence a model wrapped the passage in', function () {
    fakeRewrite("```\nThe corrected passage.\n```");

    $response = $this->signInAs($this->user)
        ->postJson('/api/text/rewrite', [
            'text' => 'Original passage.',
            'instruction' => 'Fix the grammar.',
        ])
        ->assertOk();

    expect($response->json('data.text'))->toBe('The corrected passage.');
});

it('validates the request', function () {
    $this->signInAs($this->user)
        ->postJson('/api/text/rewrite', [
            'text' => '',
            'instruction' => '',
        ])
        ->assertUnprocessable();
});

/**
 * Queue the replies the Python rewrite agent will return, one per attempt.
 *
 * The provider answers with {"text": "<model output>"}, and the service then
 * normalizes that output the same way it always has — so the awkward shapes
 * (json-wrapped, code-fenced, structurally empty) are still exercised here.
 */
function fakeRewrite(string ...$replies): void
{
    $sequence = Http::fakeSequence('*/agents/rewrite');

    foreach ($replies as $reply) {
        $sequence->push(['text' => $reply], 200);
    }
}
