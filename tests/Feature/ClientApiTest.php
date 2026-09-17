<?php

use App\Models\CaseClient;
use App\Models\Client;
use App\Models\LegalCase;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineItem;
use App\Models\PipelineStage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->proPlan = Plan::factory()->pro()->create();
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->proPlan->id]);
});

it('requires authentication', function (): void {
    $this->getJson('/api/clients')->assertUnauthorized();
});

it('creates a client with server-owned solo scope and encrypted sensitive fields', function (): void {
    Log::spy();

    $response = $this->signInAs($this->user)->postJson('/api/clients', [
        'client_type' => 'person',
        'display_name' => 'Ada Lovelace',
        'contact' => ['email' => 'ada@example.test', 'phone' => '555-0100'],
        'notes' => 'Private intake note',
    ])->assertCreated()->assertHeader('ETag');

    $client = Client::findOrFail($response->json('data.id'));

    expect($response->json('data.lifecycle'))->toBe('prospect')
        ->and($response->json('data.owner_id'))->toBe($this->user->id)
        ->and($client->contact['email'])->toBe('ada@example.test')
        ->and($client->notes)->toBe('Private intake note')
        ->and($client->getRawOriginal('contact'))->not->toContain('ada@example.test')
        ->and($client->getRawOriginal('contact'))->not->toContain('555-0100')
        ->and($client->getRawOriginal('notes'))->not->toContain('Private intake note');

    $this->assertDatabaseHas('clients', [
        'id' => $client->id,
        'owner_user_id' => $this->user->id,
        'organization_id' => null,
    ]);
    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        $serializedContext = serialize($context);

        return ! str_contains($message, 'Private intake note')
            && ! str_contains($serializedContext, 'Private intake note')
            && ! str_contains($serializedContext, 'ada@example.test')
            && ! str_contains($serializedContext, '555-0100');
    });
});

it('replays client mutations and rejects a reused key with a different request', function (): void {
    $payload = [
        'client_type' => 'person',
        'display_name' => 'Retry-safe client',
    ];

    $first = $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'create-client-once')
        ->postJson('/api/clients', $payload)
        ->assertCreated()
        ->assertHeader('ETag');

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'create-client-once')
        ->postJson('/api/clients', $payload)
        ->assertCreated()
        ->assertHeader('ETag', $first->headers->get('ETag'))
        ->assertJsonPath('data.id', $first->json('data.id'));

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'create-client-once')
        ->postJson('/api/clients', ['client_type' => 'person', 'display_name' => 'Different client'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_conflict');

    $this->assertDatabaseCount('clients', 1);
    $this->assertDatabaseCount('crm_idempotency_records', 1);
});

it('protects client updates and archive or restore mutations with ETags', function (): void {
    $client = Client::factory()->create(['owner_user_id' => $this->user->id]);
    $path = "/api/clients/{$client->id}";

    $update = $this->signInAs($this->user)
        ->patchJson($path, ['display_name' => 'Updated client'])
        ->assertOk()
        ->assertHeader('ETag');

    $archive = $this->signInAs($this->user)
        ->withHeader('If-Match', $update->headers->get('ETag'))
        ->deleteJson($path)
        ->assertOk()
        ->assertHeader('ETag');

    $this->signInAs($this->user)
        ->withHeader('If-Match', '"stale"')
        ->postJson("{$path}/restore")
        ->assertStatus(409)
        ->assertJsonPath('code', 'stale_resource');

    $this->signInAs($this->user)
        ->withHeader('If-Match', $archive->headers->get('ETag'))
        ->postJson("{$path}/restore")
        ->assertOk()
        ->assertHeader('ETag');

    $stale = $this->signInAs($this->user)
        ->withHeader('If-Match', '"stale"')
        ->patchJson($path, ['display_name' => 'Rejected update'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'stale_resource');

    expect($stale->json('data'))->toBeNull()
        ->and($client->fresh()->display_name)->toBe('Updated client');
});

it('isolates solo clients and hides guessed ids', function (): void {
    $other = User::factory()->create();
    $client = Client::factory()->create(['owner_user_id' => $other->id]);

    $this->signInAs($this->user)->getJson("/api/clients/{$client->id}")->assertNotFound();
    $this->signInAs($this->user)->getJson('/api/clients')->assertOk()->assertJsonMissing(['id' => $client->id]);
});

it('shares organization reads but restricts writes to owner or admin', function (): void {
    $organization = Organization::factory()->create();
    $owner = User::factory()->ownerOf($organization)->create();
    $member = User::factory()->memberOf($organization)->create();
    Subscription::factory()->for($organization)->for($owner)->create(['plan_id' => $this->proPlan->id]);
    $client = Client::factory()->create(['organization_id' => $organization->id, 'owner_user_id' => $owner->id]);

    $this->signInAs($member)->getJson('/api/clients')->assertOk()->assertJsonPath('data.0.id', $client->id);
    $this->signInAs($member)->patchJson("/api/clients/{$client->id}", ['display_name' => 'Nope'])->assertForbidden();
    $this->signInAs($owner)->patchJson("/api/clients/{$client->id}", ['display_name' => 'Updated'])->assertOk();
});

it('archives and restores without deleting the client', function (): void {
    $client = Client::factory()->create(['owner_user_id' => $this->user->id]);

    $this->signInAs($this->user)->deleteJson("/api/clients/{$client->id}")->assertOk();
    $this->assertDatabaseHas('clients', ['id' => $client->id]);
    $this->signInAs($this->user)->getJson('/api/clients')->assertJsonMissing(['id' => $client->id]);
    $this->signInAs($this->user)->getJson('/api/clients?archived=1')->assertJsonFragment(['id' => $client->id]);
    $this->signInAs($this->user)->postJson("/api/clients/{$client->id}/restore")->assertOk();
    expect($client->fresh()->archived_at)->toBeNull();
});

it('treats an explicit archived=0 filter as active records', function (): void {
    $active = Client::factory()->create(['owner_user_id' => $this->user->id]);
    Client::factory()->create([
        'owner_user_id' => $this->user->id,
        'archived_at' => now(),
    ]);

    $this->signInAs($this->user)
        ->getJson('/api/clients?archived=0')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id);
});

it('returns an active client profile with permitted relationship summaries', function (): void {
    $client = Client::factory()->create(['owner_user_id' => $this->user->id]);
    $pipeline = Pipeline::factory()->create(['owner_user_id' => $this->user->id]);
    $stage = PipelineStage::factory()->forPipeline($pipeline)->create();
    PipelineItem::factory()->forClient($client)->forPipeline($pipeline, $stage)->create(['title' => 'Profile intake']);
    $case = LegalCase::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => null,
        'title' => 'Profile case',
        'status' => 'open',
    ]);
    CaseClient::create([
        'case_id' => $case->id,
        'client_id' => $client->id,
        'relationship_type' => 'primary_client',
        'is_primary' => true,
        'attached_by_user_id' => $this->user->id,
    ]);

    $response = $this->signInAs($this->user)->getJson("/api/clients/{$client->id}")->assertOk();

    expect($response->json('data.pipeline_items.0'))->toMatchArray([
        'id' => PipelineItem::query()->where('client_id', $client->id)->value('id'),
        'title' => 'Profile intake',
        'pipeline' => ['name' => $pipeline->name],
        'stage' => ['name' => $stage->name],
    ])->and($response->json('data.linked_cases.0'))->toMatchArray([
        'case_id' => $case->id,
        'relationship_type' => 'primary_client',
        'relationship_label' => 'Primary client',
        'case' => [
            'id' => $case->id,
            'title' => 'Profile case',
            'reference' => $case->reference,
            'status' => 'open',
            'archived_at' => null,
        ],
    ]);
});

it('returns an authorized archived client only with the archived query flag', function (): void {
    $client = Client::factory()->create([
        'owner_user_id' => $this->user->id,
        'archived_at' => now(),
    ]);

    $this->signInAs($this->user)->getJson("/api/clients/{$client->id}")->assertNotFound();
    $this->signInAs($this->user)
        ->getJson("/api/clients/{$client->id}?archived=1")
        ->assertOk()
        ->assertJsonPath('data.id', $client->id)
        ->assertJsonPath('data.archived_at', fn ($value): bool => $value !== null);
});

it('keeps client list payloads unchanged while detail exposes only approved summary fields', function (): void {
    $client = Client::factory()->create(['owner_user_id' => $this->user->id]);
    $pipeline = Pipeline::factory()->create(['owner_user_id' => $this->user->id]);
    $stage = PipelineStage::factory()->forPipeline($pipeline)->create();
    PipelineItem::factory()->forClient($client)->forPipeline($pipeline, $stage)->create();

    $list = $this->signInAs($this->user)->getJson('/api/clients')->assertOk();
    expect($list->json('data.0'))->not->toHaveKey('pipeline_items')
        ->and($list->json('data.0'))->not->toHaveKey('linked_cases');

    $detail = $this->signInAs($this->user)->getJson("/api/clients/{$client->id}")->assertOk();
    expect(array_keys($detail->json('data.pipeline_items.0')))->toBe(['id', 'title', 'pipeline', 'stage', 'updated_at']);
});

it('excludes out-of-scope clients and relationship summaries from client profiles', function (): void {
    $other = User::factory()->create();
    $client = Client::factory()->create(['owner_user_id' => $other->id]);
    $pipeline = Pipeline::factory()->create(['owner_user_id' => $other->id]);
    $stage = PipelineStage::factory()->forPipeline($pipeline)->create();
    PipelineItem::factory()->forClient($client)->forPipeline($pipeline, $stage)->create();
    $case = LegalCase::factory()->create(['user_id' => $other->id]);
    CaseClient::create([
        'case_id' => $case->id,
        'client_id' => $client->id,
        'relationship_type' => 'additional_client',
        'is_primary' => false,
        'attached_by_user_id' => $other->id,
    ]);

    $this->signInAs($this->user)
        ->getJson("/api/clients/{$client->id}")
        ->assertNotFound();
});

it('rejects unknown and invalid client fields', function (): void {
    $this->signInAs($this->user)->postJson('/api/clients', [
        'client_type' => 'person',
        'display_name' => 'A client',
        'organization_id' => 'forged',
        'contact' => ['email' => 'not-an-email', 'secret' => 'not allowed'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['contact.email', 'contact']);
});

it('supports deterministic paginated display-name search', function (): void {
    Client::factory()->create(['owner_user_id' => $this->user->id, 'display_name' => 'Alpha']);
    Client::factory()->create(['owner_user_id' => $this->user->id, 'display_name' => 'Alpine']);

    $response = $this->signInAs($this->user)->getJson('/api/clients?q=Al&per_page=1&sort=display_name&direction=asc')->assertOk();

    expect($response->json('meta.per_page'))->toBe(1)
        ->and($response->json('meta.total'))->toBe(2)
        ->and($response->json('data.0.display_name'))->toBe('Alpha');
});
