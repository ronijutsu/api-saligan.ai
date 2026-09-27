<?php

use App\Models\Conversation;
use App\Models\LegalCase;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

it('requires authentication', function () {
    $this->getJson('/api/conversations')->assertStatus(401);
});

it('lists only the authenticated user conversations', function () {
    $own = Conversation::factory()->for($this->user)->create();
    Conversation::factory()->for(User::factory())->create();

    $response = $this->signInAs($this->user)
        ->getJson('/api/conversations')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($own->id);
});

// ai-provider picks who answers, so a new conversation has no provider until
// its first reply is saved.
it('creates a conversation without choosing a provider', function () {
    $response = $this->signInAs($this->user)
        ->postJson('/api/conversations', ['title' => 'RA 6657 research', 'provider' => 'anthropic'])
        ->assertCreated();

    expect($response->json('data.title'))->toBe('RA 6657 research')
        ->and($response->json('data.provider'))->toBeNull();

    $this->assertDatabaseHas('conversations', [
        'user_id' => $this->user->id,
        'provider' => null,
    ]);
});

it('exposes the case tags on a case conversation', function () {
    $case = LegalCase::factory()->for($this->user)->create([
        'tags' => ['land dispute', 'urgent'],
    ]);
    $conversation = Conversation::factory()->for($this->user)->for($case, 'case')->create();

    $response = $this->signInAs($this->user)
        ->getJson('/api/conversations')
        ->assertOk();

    expect($response->json('data.0.case_id'))->toBe($case->id)
        ->and($response->json('data.0.case_tags'))->toBe(['land dispute', 'urgent']);

    $this->signInAs($this->user)
        ->getJson("/api/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonPath('data.case_tags', ['land dispute', 'urgent']);
});

it('creates a conversation with a purpose', function () {
    $response = $this->signInAs($this->user)
        ->postJson('/api/conversations', ['purpose' => 'Legal research'])
        ->assertCreated();

    expect($response->json('data.purpose'))->toBe('Legal research')
        ->and($response->json('data.title'))->toBe('Legal research');
});

it('forbids attaching a conversation to another users case', function () {
    $case = LegalCase::factory()->for(User::factory())->create();

    $this->signInAs($this->user)
        ->postJson('/api/conversations', ['case_id' => $case->id])
        ->assertForbidden();
});

it('shows a conversation with its messages', function () {
    $conversation = Conversation::factory()->for($this->user)
        ->hasMessages(2)
        ->create();

    $response = $this->signInAs($this->user)
        ->getJson("/api/conversations/{$conversation->id}")
        ->assertOk();

    expect($response->json('data.messages'))->toHaveCount(2);
});

it('forbids showing another user conversation', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $this->signInAs($this->user)
        ->getJson("/api/conversations/{$conversation->id}")
        ->assertForbidden();
});

it('updates the conversation title but never its provider', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $this->signInAs($this->user)
        ->patchJson("/api/conversations/{$conversation->id}", [
            'title' => 'Renamed',
            'provider' => 'gemini',
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed')
        ->assertJsonPath('data.provider', null);
});

it('deletes a conversation', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $this->signInAs($this->user)
        ->deleteJson("/api/conversations/{$conversation->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
});

it('forbids deleting another user conversation', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $this->signInAs($this->user)
        ->deleteJson("/api/conversations/{$conversation->id}")
        ->assertForbidden();
});

it('pins a conversation', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $this->signInAs($this->user)
        ->postJson("/api/conversations/{$conversation->id}/pin")
        ->assertOk()
        ->assertJsonPath('data.id', $conversation->id)
        ->assertJsonPath('data.pinned', true)
        ->assertJsonPath('data.pinned_at', $conversation->fresh()->pinned_at?->toISOString());
});

it('unpins a conversation', function () {
    $conversation = Conversation::factory()->for($this->user)->create(['pinned_at' => now()]);

    $this->signInAs($this->user)
        ->postJson("/api/conversations/{$conversation->id}/unpin")
        ->assertOk()
        ->assertJsonPath('data.pinned', false)
        ->assertJsonPath('data.pinned_at', null);

    $this->assertNull($conversation->fresh()->pinned_at);
});

it('forbids pinning another user conversation', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $this->signInAs($this->user)
        ->postJson("/api/conversations/{$conversation->id}/pin")
        ->assertForbidden();
});

it('leads the list with pinned conversations', function () {
    $pinned = Conversation::factory()->for($this->user)->create(['pinned_at' => now()->subMinute()]);
    $recent = Conversation::factory()->for($this->user)->create();

    $this->signInAs($this->user)
        ->getJson('/api/conversations')
        ->assertOk()
        ->assertJsonPath('data.0.id', $pinned->id)
        ->assertJsonPath('data.1.id', $recent->id);
});
