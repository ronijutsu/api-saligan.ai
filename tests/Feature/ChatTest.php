<?php

use App\Models\Conversation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'saligan.ai_provider.url' => 'http://ai-provider.test',
        'saligan.ai_provider.internal_secret' => 'shared-secret',
    ]);

    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);

    Http::preventStrayRequests();
    Http::fake(['ai-provider.test/*' => Http::response("event: done\ndata: {\"ok\":true,\"web_citations\":0}\n\n")]);
});

it('validates the message payload', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $this->signInAs($this->user)
        ->postJson("/api/conversations/{$conversation->id}/messages", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message');

    Http::assertNothingSent();
});

it('forbids messaging another user conversation', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $this->signInAs($this->user)
        ->post("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Hello',
        ])
        ->assertForbidden();

    Http::assertNothingSent();
});
