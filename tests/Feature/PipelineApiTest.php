<?php

use App\Models\Client;
use App\Models\CrmIdempotencyRecord;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineItem;
use App\Models\PipelineStage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->proPlan = Plan::factory()->pro()->create();
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->proPlan->id]);
});

it('requires authentication for pipeline reads', function (): void {
    $this->getJson('/api/pipelines')->assertUnauthorized();
});

it('returns the CRM validation envelope for unknown pipeline fields', function (): void {
    $this->signInAs($this->user)
        ->postJson('/api/pipelines', [
            'name' => 'Intake',
            'owner_id' => $this->user->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['owner_id'], 'request_id']);
});

it('rejects an invalid idempotency key before creating a pipeline', function (): void {
    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', str_repeat('k', 129))
        ->postJson('/api/pipelines', ['name' => 'Intake'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_idempotency_key');

    $this->assertDatabaseCount('pipelines', 0);
});

it('replays a pipeline mutation and rejects a reused key with a different request', function (): void {
    $payload = ['name' => 'Retry-safe intake'];

    $first = $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'create-pipeline-once')
        ->postJson('/api/pipelines', $payload)
        ->assertCreated()
        ->assertHeader('ETag');

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'create-pipeline-once')
        ->postJson('/api/pipelines', $payload)
        ->assertCreated()
        ->assertHeader('ETag', $first->headers->get('ETag'))
        ->assertJsonPath('data.id', $first->json('data.id'));

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'create-pipeline-once')
        ->postJson('/api/pipelines', ['name' => 'A different intake'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_conflict');

    $this->assertDatabaseCount('pipelines', 1);
    $this->assertDatabaseCount('crm_idempotency_records', 1);

    $record = CrmIdempotencyRecord::query()
        ->where('scope_key', 'user:'.$this->user->id.'|solo')
        ->where('idempotency_key', 'create-pipeline-once')
        ->firstOrFail();

    expect($record->getRawOriginal('response_body'))->not->toContain('Retry-safe intake');
});

it('allows an idempotency key to be reused after its retention window', function (): void {
    config()->set('crm.idempotency_retention_hours', 1);

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'expired-pipeline-key')
        ->postJson('/api/pipelines', ['name' => 'First intake'])
        ->assertCreated();

    $this->travel(2)->hours();

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'expired-pipeline-key')
        ->postJson('/api/pipelines', ['name' => 'Second intake'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Second intake');

    $this->assertDatabaseCount('pipelines', 2);
    $this->assertDatabaseCount('crm_idempotency_records', 1);
});

it('allows an item idempotency key to be reused after its retention window', function (): void {
    config()->set('crm.idempotency_retention_hours', 1);

    $pipeline = pipelineFor($this->user, [
        ['name' => 'Open', 'outcome_kind' => 'open'],
    ]);
    $client = Client::factory()->create(['owner_user_id' => $this->user->id]);
    $stage = $pipeline->stages()->firstOrFail();

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'reusable-item-key')
        ->postJson('/api/pipeline-items', [
            'client_id' => $client->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'title' => 'First intake',
        ])
        ->assertCreated();

    $this->travel(2)->hours();

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'reusable-item-key')
        ->postJson('/api/pipeline-items', [
            'client_id' => $client->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'title' => 'Second intake',
        ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Second intake');

    $this->assertDatabaseCount('pipeline_items', 2);
    $this->assertDatabaseCount('crm_idempotency_records', 1);
});

it('does not replay an idempotency result across authenticated users', function (): void {
    $other = User::factory()->create();
    Subscription::factory()->for($other)->create(['plan_id' => $this->proPlan->id]);

    $first = $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'same-key-different-user')
        ->postJson('/api/pipelines', ['name' => 'Private intake'])
        ->assertCreated();

    $second = $this->signInAs($other)
        ->withHeader('Idempotency-Key', 'same-key-different-user')
        ->postJson('/api/pipelines', ['name' => 'Private intake'])
        ->assertCreated();

    expect($second->json('data.id'))->not->toBe($first->json('data.id'));
    $this->assertDatabaseCount('pipelines', 2);
    $this->assertDatabaseCount('crm_idempotency_records', 2);
});

it('prunes expired idempotency records', function (): void {
    CrmIdempotencyRecord::create([
        'scope_key' => 'user:expired|solo',
        'idempotency_key' => 'expired-record',
        'request_fingerprint' => str_repeat('a', 64),
        'expires_at' => now()->subMinute(),
    ]);
    CrmIdempotencyRecord::create([
        'scope_key' => 'user:active|solo',
        'idempotency_key' => 'active-record',
        'request_fingerprint' => str_repeat('b', 64),
        'expires_at' => now()->addMinute(),
    ]);

    $this->artisan('crm:prune-idempotency')
        ->expectsOutput('Expired CRM idempotency records deleted: 1')
        ->assertSuccessful();

    $this->assertDatabaseCount('crm_idempotency_records', 1);
    $this->assertDatabaseHas('crm_idempotency_records', ['idempotency_key' => 'active-record']);
});

it('replays a deterministic mutation conflict for the same idempotency key', function (): void {
    $pipeline = pipelineFor($this->user, [
        ['name' => 'First', 'outcome_kind' => 'open'],
        ['name' => 'Second', 'outcome_kind' => 'open'],
    ]);
    $client = Client::factory()->create(['owner_user_id' => $this->user->id]);
    $stage = $pipeline->stages()->firstOrFail();

    $this->signInAs($this->user)
        ->postJson('/api/pipeline-items', [
            'client_id' => $client->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'title' => 'Conflict-safe intake',
        ])
        ->assertCreated();

    $path = "/api/pipelines/{$pipeline->id}/stages/{$stage->id}";

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'archive-used-stage-once')
        ->deleteJson($path)
        ->assertStatus(409)
        ->assertJsonPath('code', 'stage_in_use');

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'archive-used-stage-once')
        ->deleteJson($path)
        ->assertStatus(409)
        ->assertJsonPath('code', 'stage_in_use');

    $this->assertDatabaseCount('crm_idempotency_records', 1);
});

it('creates the approved default stages when no stages are supplied', function (): void {
    $response = $this->signInAs($this->user)
        ->postJson('/api/pipelines', ['name' => 'Intake'])
        ->assertCreated()
        ->assertHeader('ETag');

    expect($response->json('data.is_default'))->toBeTrue()
        ->and($response->json('data.stages'))->toHaveCount(6)
        ->and($response->json('data.stages.0'))->toMatchArray([
            'name' => 'New inquiry',
            'position' => 0,
            'outcome_kind' => 'open',
        ])
        ->and($response->json('data.stages.4.outcome_kind'))->toBe('won')
        ->and($response->json('data.stages.5.outcome_kind'))->toBe('not_proceeding');

    $this->assertDatabaseCount('pipelines', 1);
    $this->assertDatabaseCount('pipeline_stages', 6);
});

it('isolates solo pipelines and guessed ids', function (): void {
    $other = User::factory()->create();
    $pipeline = Pipeline::factory()->create(['owner_user_id' => $other->id]);

    $this->signInAs($this->user)
        ->getJson('/api/pipelines')
        ->assertOk()
        ->assertJsonMissing(['id' => $pipeline->id]);

    $this->signInAs($this->user)
        ->getJson("/api/pipelines/{$pipeline->id}")
        ->assertNotFound();
});

it('allows organization members to read while limiting pipeline configuration', function (): void {
    $organization = Organization::factory()->create();
    $owner = User::factory()->ownerOf($organization)->create();
    $member = User::factory()->memberOf($organization)->create();
    Subscription::factory()->for($organization)->for($owner)->create(['plan_id' => $this->proPlan->id]);
    $pipeline = Pipeline::factory()->forOrganization($organization, $owner)->create();

    $this->signInAs($member)
        ->getJson('/api/pipelines')
        ->assertOk()
        ->assertJsonPath('data.0.id', $pipeline->id);

    $this->signInAs($member)
        ->patchJson("/api/pipelines/{$pipeline->id}", ['name' => 'Nope'])
        ->assertForbidden();

    $this->signInAs($owner)
        ->patchJson("/api/pipelines/{$pipeline->id}", ['name' => 'Updated'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated');
});

it('archives and restores pipelines and items without leaving orphaned active records', function (): void {
    $pipeline = pipelineFor($this->user, [
        ['name' => 'First', 'outcome_kind' => 'open'],
        ['name' => 'Second', 'outcome_kind' => 'open'],
    ]);
    $client = Client::factory()->create(['owner_user_id' => $this->user->id]);
    $stage = $pipeline->stages()->firstOrFail();

    $item = $this->signInAs($this->user)
        ->postJson('/api/pipeline-items', [
            'client_id' => $client->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'title' => 'Archive-safe intake',
        ])
        ->assertCreated()
        ->json('data');

    $pipelineResponse = $this->signInAs($this->user)
        ->getJson("/api/pipelines/{$pipeline->id}")
        ->assertOk();
    $pipelineEtag = $pipelineResponse->headers->get('ETag');

    $this->signInAs($this->user)
        ->deleteJson("/api/pipelines/{$pipeline->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'pipeline_has_active_items');

    $archivedItem = $this->signInAs($this->user)
        ->deleteJson("/api/pipeline-items/{$item['id']}")
        ->assertOk()
        ->assertJsonPath('data.archived_at', fn (mixed $value): bool => $value !== null);
    $itemEtag = $archivedItem->headers->get('ETag');

    $archivedPipeline = $this->signInAs($this->user)
        ->withHeader('If-Match', $pipelineEtag)
        ->deleteJson("/api/pipelines/{$pipeline->id}")
        ->assertOk()
        ->assertJsonPath('data.archived_at', fn (mixed $value): bool => $value !== null);

    $this->signInAs($this->user)
        ->getJson("/api/pipelines/{$pipeline->id}")
        ->assertNotFound();

    $restoredPipeline = $this->signInAs($this->user)
        ->withHeader('If-Match', $archivedPipeline->headers->get('ETag'))
        ->postJson("/api/pipelines/{$pipeline->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.archived_at', null)
        ->assertJsonPath('data.is_default', true);

    $this->signInAs($this->user)
        ->withHeader('If-Match', $itemEtag)
        ->postJson("/api/pipeline-items/{$item['id']}/restore")
        ->assertOk()
        ->assertJsonPath('data.archived_at', null)
        ->assertJsonPath('data.pipeline_id', $pipeline->id);

    expect($restoredPipeline->json('data.stages'))->toHaveCount(2);
});

it('treats an explicit archived=0 filter as active records', function (): void {
    $activePipeline = pipelineFor($this->user, [
        ['name' => 'Open', 'outcome_kind' => 'open'],
    ]);
    $archivedPipeline = Pipeline::factory()->create([
        'owner_user_id' => $this->user->id,
        'archived_at' => now(),
    ]);
    $client = Client::factory()->create(['owner_user_id' => $this->user->id]);
    $activeStage = $activePipeline->stages()->firstOrFail();
    $activeItem = PipelineItem::factory()->create([
        'client_id' => $client->id,
        'pipeline_id' => $activePipeline->id,
        'stage_id' => $activeStage->id,
        'owner_user_id' => $this->user->id,
    ]);
    $archivedStage = PipelineStage::factory()->forPipeline($archivedPipeline, 0)->create([
        'name' => 'Archived open',
        'outcome_kind' => 'open',
    ]);
    PipelineItem::factory()->create([
        'client_id' => $client->id,
        'pipeline_id' => $archivedPipeline->id,
        'stage_id' => $archivedStage->id,
        'owner_user_id' => $this->user->id,
        'archived_at' => now(),
    ]);

    $this->signInAs($this->user)
        ->getJson('/api/pipelines?archived=0')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $activePipeline->id);

    $this->signInAs($this->user)
        ->getJson('/api/pipeline-items?archived=0')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $activeItem->id);
});

it('keeps exactly one active default pipeline when the current default changes', function (): void {
    $default = $this->signInAs($this->user)
        ->postJson('/api/pipelines', ['name' => 'Default intake'])
        ->assertCreated()
        ->json('data');
    $replacement = $this->signInAs($this->user)
        ->postJson('/api/pipelines', ['name' => 'Replacement intake'])
        ->assertCreated()
        ->json('data');

    $this->signInAs($this->user)
        ->patchJson("/api/pipelines/{$default['id']}", ['is_default' => false])
        ->assertOk()
        ->assertJsonPath('data.is_default', false);

    expect(Pipeline::findOrFail($replacement['id'])->is_default)->toBeTrue()
        ->and(Pipeline::query()->where('owner_user_id', $this->user->id)->where('is_default', true)->count())->toBe(1);
});

it('configures stages and requires an atomic replacement for used stages', function (): void {
    $pipeline = pipelineFor($this->user, [
        ['name' => 'First', 'outcome_kind' => 'open'],
        ['name' => 'Second', 'outcome_kind' => 'open'],
    ]);
    $client = Client::factory()->create(['owner_user_id' => $this->user->id]);
    $firstStage = $pipeline->stages()->firstOrFail();
    $secondStage = $pipeline->stages()->orderBy('position')->skip(1)->firstOrFail();

    $createdStage = $this->signInAs($this->user)
        ->postJson("/api/pipelines/{$pipeline->id}/stages", [
            'name' => 'Third',
            'outcome_kind' => 'open',
        ])
        ->assertCreated()
        ->json('data');

    expect($createdStage['position'])->toBe(2);

    $this->signInAs($this->user)
        ->patchJson("/api/pipelines/{$pipeline->id}/stages/{$createdStage['id']}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');

    $this->signInAs($this->user)
        ->postJson("/api/pipelines/{$pipeline->id}/stages/reorder", [
            'stage_ids' => [$createdStage['id'], $secondStage->id, $firstStage->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.stages.0.id', $createdStage['id']);

    $this->signInAs($this->user)
        ->postJson("/api/pipelines/{$pipeline->id}/stages/reorder", [
            'stage_ids' => [$createdStage['id']],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_stage_order');

    $item = $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'create-used-stage')
        ->postJson('/api/pipeline-items', [
            'client_id' => $client->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $firstStage->id,
            'title' => 'Initial intake',
        ])
        ->assertCreated()
        ->json('data');

    $this->flushHeaders();
    $this->signInAs($this->user)
        ->deleteJson("/api/pipelines/{$pipeline->id}/stages/{$firstStage->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'stage_in_use');

    $this->signInAs($this->user)
        ->deleteJson("/api/pipelines/{$pipeline->id}/stages/{$firstStage->id}", [
            'replacement_stage_id' => $secondStage->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.archived_at', fn (mixed $value): bool => $value !== null);

    expect(PipelineItem::findOrFail($item['id'])->stage_id)->toBe($secondStage->id)
        ->and(PipelineItem::findOrFail($item['id'])->history()->count())->toBe(2);
});

it('restores an archived stage and appends it when its original position is occupied', function (): void {
    $pipeline = pipelineFor($this->user, [
        ['name' => 'First', 'outcome_kind' => 'open'],
        ['name' => 'Second', 'outcome_kind' => 'open'],
        ['name' => 'Third', 'outcome_kind' => 'open'],
    ]);
    $stages = $pipeline->stages()->orderBy('position')->get();
    $archived = $stages->get(0);
    $second = $stages->get(1);
    $third = $stages->get(2);

    $archivedResponse = $this->signInAs($this->user)
        ->deleteJson("/api/pipelines/{$pipeline->id}/stages/{$archived->id}")
        ->assertOk()
        ->assertJsonPath('data.archived_at', fn (mixed $value): bool => $value !== null);

    $this->signInAs($this->user)
        ->postJson("/api/pipelines/{$pipeline->id}/stages/reorder", [
            'stage_ids' => [$third->id, $second->id],
        ])
        ->assertOk();

    $restored = $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'restore-stage-once')
        ->withHeader('If-Match', $archivedResponse->headers->get('ETag'))
        ->postJson("/api/pipelines/{$pipeline->id}/stages/{$archived->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.archived_at', null)
        ->assertJsonPath('data.position', 2);

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'restore-stage-once')
        ->withHeader('If-Match', $archivedResponse->headers->get('ETag'))
        ->postJson("/api/pipelines/{$pipeline->id}/stages/{$archived->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $restored->json('data.id'))
        ->assertJsonPath('data.position', 2);

    expect($pipeline->allStages()->active()->orderBy('position')->pluck('name')->all())
        ->toBe(['Third', 'Second', 'First']);
});

it('creates scoped items and makes stage moves atomic, terminal-safe, and idempotent', function (): void {
    $pipeline = pipelineFor($this->user, [
        ['name' => 'Open', 'outcome_kind' => 'open'],
        ['name' => 'Review', 'outcome_kind' => 'open'],
        ['name' => 'Won', 'outcome_kind' => 'won'],
    ]);
    $otherPipeline = pipelineFor($this->user, [
        ['name' => 'Other', 'outcome_kind' => 'open'],
    ]);
    $client = Client::factory()->create(['owner_user_id' => $this->user->id]);
    $open = $pipeline->stages()->orderBy('position')->firstOrFail();
    $review = $pipeline->stages()->orderBy('position')->skip(1)->firstOrFail();
    $won = $pipeline->stages()->orderBy('position')->skip(2)->firstOrFail();
    $otherStage = $otherPipeline->stages()->firstOrFail();

    $this->signInAs($this->user)
        ->postJson('/api/pipeline-items', [
            'client_id' => $client->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $otherStage->id,
            'title' => 'Invalid intake',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_pipeline_stage');
    $this->assertDatabaseCount('pipeline_items', 0);

    $payload = [
        'client_id' => $client->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $open->id,
        'title' => 'Valid intake',
        'source' => 'Website',
        'note' => 'Needs a consultation.',
    ];
    $create = $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'create-valid-item')
        ->postJson('/api/pipeline-items', $payload)
        ->assertCreated()
        ->assertHeader('ETag');
    $itemId = $create->json('data.id');

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'create-valid-item')
        ->postJson('/api/pipeline-items', $payload)
        ->assertCreated()
        ->assertJsonPath('data.id', $itemId);
    $this->assertDatabaseCount('pipeline_items', 1);

    $moved = $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'move-to-review')
        ->withHeader('If-Match', $create->headers->get('ETag'))
        ->patchJson("/api/pipeline-items/{$itemId}/stage", ['stage_id' => $review->id])
        ->assertOk()
        ->assertJsonPath('data.stage_id', $review->id)
        ->assertHeader('ETag');

    $historyUrl = "/api/pipeline-items/{$itemId}/history";
    $this->signInAs($this->user)
        ->getJson($historyUrl)
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'move-to-review')
        ->withHeader('If-Match', $moved->headers->get('ETag'))
        ->patchJson("/api/pipeline-items/{$itemId}/stage", ['stage_id' => $review->id])
        ->assertOk()
        ->assertJsonPath('data.stage_id', $review->id);
    expect(PipelineItem::findOrFail($itemId)->history()->count())->toBe(2);

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'move-to-review')
        ->patchJson("/api/pipeline-items/{$itemId}/stage", ['stage_id' => $won->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_conflict');

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'stale-move')
        ->withHeader('If-Match', $create->headers->get('ETag'))
        ->patchJson("/api/pipeline-items/{$itemId}/stage", ['stage_id' => $won->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'stale_resource');
    expect(PipelineItem::findOrFail($itemId)->stage_id)->toBe($review->id);

    $terminal = $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'move-to-won')
        ->withHeader('If-Match', $moved->headers->get('ETag'))
        ->patchJson("/api/pipeline-items/{$itemId}/stage", ['stage_id' => $won->id])
        ->assertOk()
        ->assertJsonPath('data.stage_id', $won->id);

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'move-after-won')
        ->withHeader('If-Match', $terminal->headers->get('ETag'))
        ->patchJson("/api/pipeline-items/{$itemId}/stage", ['stage_id' => $review->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'terminal_stage');
    expect(PipelineItem::findOrFail($itemId)->history()->count())->toBe(3);
});

it('allows a stage-move idempotency key to be reused after its retention window', function (): void {
    config()->set('crm.idempotency_retention_hours', 1);

    $pipeline = pipelineFor($this->user, [
        ['name' => 'Open', 'outcome_kind' => 'open'],
        ['name' => 'Review', 'outcome_kind' => 'open'],
    ]);
    $client = Client::factory()->create(['owner_user_id' => $this->user->id]);
    $stages = $pipeline->stages()->orderBy('position')->get();

    $created = $this->signInAs($this->user)
        ->postJson('/api/pipeline-items', [
            'client_id' => $client->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stages[0]->id,
            'title' => 'Reusable move',
        ])
        ->assertCreated();

    $moved = $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'reusable-move-key')
        ->withHeader('If-Match', $created->headers->get('ETag'))
        ->patchJson('/api/pipeline-items/'.$created->json('data.id').'/stage', ['stage_id' => $stages[1]->id])
        ->assertOk();

    $this->travel(2)->hours();

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'reusable-move-key')
        ->withHeader('If-Match', $moved->headers->get('ETag'))
        ->patchJson('/api/pipeline-items/'.$created->json('data.id').'/stage', ['stage_id' => $stages[0]->id])
        ->assertOk()
        ->assertJsonPath('data.stage_id', $stages[0]->id);

    $history = PipelineItem::findOrFail($created->json('data.id'))->history()->pluck('request_id')->filter();

    expect($history)->toHaveCount(3)
        ->and($history->unique())->toHaveCount(3);
});

it('does not expose an item whose client is outside the authenticated scope', function (): void {
    $pipeline = pipelineFor($this->user, [
        ['name' => 'Open', 'outcome_kind' => 'open'],
    ]);
    $other = User::factory()->create();
    $client = Client::factory()->create(['owner_user_id' => $other->id]);
    $stage = $pipeline->stages()->firstOrFail();
    $item = PipelineItem::factory()->create([
        'client_id' => $client->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_user_id' => $this->user->id,
    ]);

    $this->signInAs($this->user)
        ->getJson("/api/pipeline-items/{$item->id}")
        ->assertNotFound();

    $this->signInAs($this->user)
        ->getJson('/api/pipeline-items')
        ->assertOk()
        ->assertJsonMissing(['id' => $item->id]);
});

/**
 * @param  array<int, array{name: string, outcome_kind: string}>  $stages
 */
function pipelineFor(User $owner, array $stages): Pipeline
{
    $pipeline = Pipeline::factory()->create([
        'owner_user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
    ]);

    foreach ($stages as $position => $stage) {
        PipelineStage::factory()
            ->forPipeline($pipeline, $position)
            ->create($stage);
    }

    return $pipeline->load('stages');
}
