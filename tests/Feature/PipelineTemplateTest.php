<?php

use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->proPlan = Plan::factory()->pro()->create();
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->proPlan->id]);
});

it('returns the approved catalogue in deterministic order', function (): void {
    $this->signInAs($this->user)
        ->getJson('/api/pipeline-templates')
        ->assertOk()
        ->assertJsonPath('data.0.template_key', 'standard_intake_v1')
        ->assertJsonPath('data.1.template_key', 'compact_intake_v1')
        ->assertJsonPath('data.2.template_key', 'consultation_first_v1')
        ->assertJsonCount(6, 'data.0.stages')
        ->assertJsonCount(5, 'data.1.stages')
        ->assertJsonPath('data.2.stages.1.name', 'Consultation');
});

it('creates a pipeline from a selected template and records provenance', function (): void {
    $this->signInAs($this->user)
        ->postJson('/api/pipelines', ['template_key' => 'compact_intake_v1'])
        ->assertCreated()
        ->assertJsonPath('data.template_key', 'compact_intake_v1')
        ->assertJsonPath('data.name', 'Compact intake')
        ->assertJsonCount(5, 'data.stages');

    expect(Pipeline::query()->firstOrFail()->auto_provisioned)->toBeFalse();
});

it('rejects template and stages together without writing', function (): void {
    $this->signInAs($this->user)
        ->postJson('/api/pipelines', [
            'template_key' => 'compact_intake_v1',
            'stages' => [['name' => 'Custom', 'outcome_kind' => 'open']],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'template_input_conflict');

    $this->assertDatabaseCount('pipelines', 0);
});

it('rejects an unapproved template without writing', function (): void {
    $this->signInAs($this->user)
        ->postJson('/api/pipelines', ['template_key' => 'unpublished_v1'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_template');

    $this->assertDatabaseCount('pipelines', 0);
});

it('provisions once and replays the existing record after archive', function (): void {
    $first = $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'provision-once')
        ->postJson('/api/pipelines/provision', ['template_key' => 'compact_intake_v1'])
        ->assertCreated()
        ->assertJsonPath('data.auto_provisioned', true)
        ->assertJsonPath('data.template_key', 'compact_intake_v1');

    $pipelineId = $first->json('data.id');

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'provision-replay')
        ->postJson('/api/pipelines/provision', ['template_key' => 'consultation_first_v1'])
        ->assertOk()
        ->assertJsonPath('data.id', $pipelineId)
        ->assertJsonPath('data.template_key', 'compact_intake_v1');

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'archive-provisioned-pipeline')
        ->deleteJson("/api/pipelines/{$pipelineId}")
        ->assertOk();

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'provision-after-archive')
        ->postJson('/api/pipelines/provision')
        ->assertOk()
        ->assertJsonPath('data.id', $pipelineId);

    $this->assertDatabaseCount('pipelines', 1);
});

it('forbids organization members from configuring pipelines', function (): void {
    $organization = Organization::factory()->create();
    $owner = User::factory()->ownerOf($organization)->create();
    $member = User::factory()->memberOf($organization)->create();
    Subscription::factory()->for($organization)->for($owner)->create(['plan_id' => $this->proPlan->id]);

    $this->signInAs($member)
        ->withHeader('Idempotency-Key', 'member-provision')
        ->postJson('/api/pipelines/provision')
        ->assertForbidden();
});
