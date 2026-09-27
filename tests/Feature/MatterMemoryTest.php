<?php

use App\Models\LegalCase;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MatterMemory\MatterMemoryService;

test('matter memory service stores and retrieves memories', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $service = new MatterMemoryService;

    $memory = $service->store($case, $user, 'fact', 'The hearing is scheduled for March 15, 2026');

    expect($memory->organization_id)->toBe($organization->id)
        ->and($memory->case_id)->toBe($case->id)
        ->and($memory->user_id)->toBe($user->id)
        ->and($memory->type)->toBe('fact')
        ->and($memory->content)->toBe('The hearing is scheduled for March 15, 2026')
        ->and($memory->is_active)->toBeTrue();

    $memories = $service->getMemories($case);
    expect($memories)->toHaveCount(1)
        ->and($memories->first()->id)->toBe($memory->id);
});

test('matter memory service scopes by case', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $caseA = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);
    $caseB = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $service = new MatterMemoryService;

    $service->store($caseA, $user, 'fact', 'Fact for case A');
    $service->store($caseB, $user, 'fact', 'Fact for case B');

    $memoriesA = $service->getMemories($caseA);
    $memoriesB = $service->getMemories($caseB);

    expect($memoriesA)->toHaveCount(1)
        ->and($memoriesA->first()->content)->toBe('Fact for case A')
        ->and($memoriesB)->toHaveCount(1)
        ->and($memoriesB->first()->content)->toBe('Fact for case B');
});

test('matter memory service filters by type', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $service = new MatterMemoryService;

    $service->store($case, $user, 'fact', 'A fact');
    $service->store($case, $user, 'strategy', 'A strategy');
    $service->store($case, $user, 'deadline', 'A deadline');

    $facts = $service->getMemories($case, 'fact');
    expect($facts)->toHaveCount(1)
        ->and($facts->first()->content)->toBe('A fact');

    $strategies = $service->getMemories($case, 'strategy');
    expect($strategies)->toHaveCount(1)
        ->and($strategies->first()->content)->toBe('A strategy');
});

test('matter memory service detects duplicates', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $service = new MatterMemoryService;

    $service->store($case, $user, 'fact', 'The hearing is March 15');

    expect($service->existsSimilar($case, 'fact', 'The hearing is March 15'))->toBeTrue()
        ->and($service->existsSimilar($case, 'fact', 'Different fact'))->toBeFalse()
        ->and($service->existsSimilar($case, 'strategy', 'The hearing is March 15'))->toBeFalse();
});

test('matter memory service blocks writes on legal hold', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create([
        'organization_id' => $organization->id,
        'retention_status' => 'on-legal-hold',
    ]);

    $service = new MatterMemoryService;

    expect($service->canWrite($case))->toBeFalse()
        ->and($service->isOnLegalHold($case))->toBeTrue();
});

test('matter memory service blocks writes on pending deletion', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create([
        'organization_id' => $organization->id,
        'retention_status' => 'closed-pending-deletion',
    ]);

    $service = new MatterMemoryService;

    expect($service->canWrite($case))->toBeFalse();
});

test('matter memory service allows writes on active cases', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create([
        'organization_id' => $organization->id,
        'retention_status' => 'active',
    ]);

    $service = new MatterMemoryService;

    expect($service->canWrite($case))->toBeTrue()
        ->and($service->isOnLegalHold($case))->toBeFalse();
});

test('matter memory service generates memory block for prompt', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $service = new MatterMemoryService;

    $service->store($case, $user, 'fact', 'The hearing is March 15');
    $service->store($case, $user, 'strategy', 'Focus on procedural defects');

    $block = $service->getMemoryBlock($case);

    expect($block)->toContain('[fact]')
        ->and($block)->toContain('The hearing is March 15')
        ->and($block)->toContain('[[UNTRUSTED DATA START]]')
        ->and($block)->toContain('[[UNTRUSTED DATA END]]')
        ->and($block)->toContain('[strategy]')
        ->and($block)->toContain('Focus on procedural defects');
});

test('matter memory service returns empty message when no memories', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    $case = LegalCase::factory()->for($user)->create(['organization_id' => $organization->id]);

    $service = new MatterMemoryService;

    $block = $service->getMemoryBlock($case);

    expect($block)->toBe('No matter-specific memory entries recorded for this matter.');
});

test('matter memory works for a solo user whose case has no organization', function () {
    // cases.organization_id and users.organization_id are both nullable, so a
    // solo user's case carries no organization. matter_memory.organization_id
    // was NOT NULL, which made every write-back on such a case fail on insert.
    $user = User::factory()->create(['organization_id' => null]);
    $case = LegalCase::factory()->for($user)->create(['organization_id' => null]);

    $service = new MatterMemoryService;

    $service->store($case, $user, 'fact', 'DPWH took possession of the 1,200 sq. m. portion');

    $memories = $service->getMemories($case);

    expect($memories)->toHaveCount(1)
        ->and($memories->first()->organization_id)->toBeNull()
        ->and($memories->first()->content)->toBe('DPWH took possession of the 1,200 sq. m. portion');
});

test('a case adopts its owner organization and memory follows the case', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();
    Subscription::factory()->for($user)->create(['plan_id' => Plan::factory()->pro()->create()->id]);

    $this->signInAs($user)
        ->postJson('/api/cases', [
            'title' => 'Villanueva v. DPWH',
            'case_type' => 'expropriation',
            'status' => 'open',
        ])
        ->assertCreated();

    $case = LegalCase::where('user_id', $user->id)->firstOrFail();

    expect($case->organization_id)->toBe($organization->id);

    $memory = (new MatterMemoryService)->store($case, $user, 'fact', 'DPWH took possession');

    expect($memory->organization_id)->toBe($organization->id);
});

test('memory adopts the author organization when the case predates the backfill', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->for($organization)->create();

    // A case created before organization_id was written on creation.
    $case = LegalCase::factory()->for($user)->create(['organization_id' => null]);

    $memory = (new MatterMemoryService)->store($case, $user, 'fact', 'A durable fact');

    expect($memory->organization_id)->toBe($organization->id);
});
