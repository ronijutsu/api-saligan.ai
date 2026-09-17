<?php

use App\Models\CaseClient;
use App\Models\Client;
use App\Models\LegalCase;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create(['plan_id' => Plan::factory()->pro()->create()->id]);
    $this->case = LegalCase::factory()->for($this->user)->create(['organization_id' => null, 'status' => 'open']);
    $this->client = Client::factory()->create(['owner_user_id' => $this->user->id]);
});

function attachPayload(string $caseId, string $clientId, string $type = 'primary_client'): array
{
    return ['case_id' => $caseId, 'client_id' => $clientId, 'relationship_type' => $type, 'is_primary' => $type === 'primary_client'];
}

it('attaches and reads a case client link from both directions', function (): void {
    $response = $this->signInAs($this->user)->withHeader('Idempotency-Key', 'attach-link')
        ->postJson("/api/clients/{$this->client->id}/cases", ['case_id' => $this->case->id, 'relationship_type' => 'primary_client', 'is_primary' => true])
        ->assertCreated()
        ->assertJsonPath('data.relationship_type', 'primary_client')
        ->assertJsonPath('data.relationship_label', 'Primary client')
        ->assertJsonPath('data.is_primary', true);

    $this->signInAs($this->user)->getJson("/api/clients/{$this->client->id}/cases")
        ->assertOk()->assertJsonPath('data.0.case_id', $this->case->id);
    $this->signInAs($this->user)->getJson("/api/cases/{$this->case->id}/clients")
        ->assertOk()->assertJsonPath('data.0.client_id', $this->client->id);
    expect($response->json('data.case'))->toBeArray();
});

it('rejects relationship bodies containing the wrong-side or unknown fields', function (): void {
    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'wrong-side-client')
        ->postJson("/api/clients/{$this->client->id}/cases", attachPayload($this->case->id, $this->client->id))
        ->assertStatus(422)
        ->assertJsonPath('errors.client_id.0', 'This field is not permitted.');

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'wrong-side-case')
        ->postJson("/api/cases/{$this->case->id}/clients", [
            'client_id' => $this->client->id,
            'case_id' => $this->case->id,
            'relationship_type' => 'primary_client',
            'is_primary' => true,
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.case_id.0', 'This field is not permitted.');

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'unknown-detach')
        ->deleteJson("/api/cases/{$this->case->id}/clients/{$this->client->id}", ['unexpected' => true])
        ->assertStatus(422)
        ->assertJsonPath('errors.unexpected.0', 'This field is not permitted.');
});

it('rejects invalid relationship pagination and sort values', function (): void {
    $this->signInAs($this->user)->getJson("/api/cases/{$this->case->id}/clients?per_page=101")->assertStatus(422);
    $this->signInAs($this->user)->getJson("/api/cases/{$this->case->id}/clients?sort=display_name")->assertStatus(422);
    $this->signInAs($this->user)->getJson("/api/cases/{$this->case->id}/clients?page=0")->assertStatus(422);
});

it('rejects a visible case and client from different scopes inside the transaction', function (): void {
    $this->case->update(['organization_id' => Organization::factory()->create()->id]);

    $this->signInAs($this->user)
        ->withHeader('Idempotency-Key', 'cross-scope')
        ->postJson("/api/cases/{$this->case->id}/clients", [
            'client_id' => $this->client->id,
            'relationship_type' => 'primary_client',
            'is_primary' => true,
        ])
        ->assertForbidden();

    expect(CaseClient::count())->toBe(0);
});

it('keeps archived linked clients visible from an authorized case roster', function (): void {
    CaseClient::create([
        'case_id' => $this->case->id,
        'client_id' => $this->client->id,
        'relationship_type' => 'primary_client',
        'is_primary' => true,
        'attached_by_user_id' => $this->user->id,
    ]);
    $this->client->forceFill(['archived_at' => now()])->save();

    $this->signInAs($this->user)
        ->getJson("/api/cases/{$this->case->id}/clients")
        ->assertOk()
        ->assertJsonPath('data.0.client_id', $this->client->id)
        ->assertJsonPath('data.0.client.archived_at', fn ($value) => $value !== null);
});

it('uses an id tie-breaker for deterministic relationship pagination', function (): void {
    $additional = Client::factory()->create(['owner_user_id' => $this->user->id]);
    CaseClient::create([
        'case_id' => $this->case->id,
        'client_id' => $this->client->id,
        'relationship_type' => 'primary_client',
        'is_primary' => true,
        'attached_by_user_id' => $this->user->id,
    ]);
    CaseClient::create([
        'case_id' => $this->case->id,
        'client_id' => $additional->id,
        'relationship_type' => 'additional_client',
        'is_primary' => false,
        'attached_by_user_id' => $this->user->id,
    ]);

    $first = $this->signInAs($this->user)->getJson("/api/cases/{$this->case->id}/clients?sort=created_at&direction=asc&per_page=1&page=1")->assertOk();
    $second = $this->signInAs($this->user)->getJson("/api/cases/{$this->case->id}/clients?sort=created_at&direction=asc&per_page=1&page=2")->assertOk();

    expect($first->json('data.0.client_id'))->not->toBe($second->json('data.0.client_id'));
    expect($first->json('meta.per_page'))->toBe(1);
});

it('replays attach and rejects duplicate links', function (): void {
    $path = "/api/cases/{$this->case->id}/clients";
    $payload = ['client_id' => $this->client->id, 'relationship_type' => 'primary_client', 'is_primary' => true];
    $this->signInAs($this->user)->withHeader('Idempotency-Key', 'same-attach')->postJson($path, $payload)->assertCreated();
    $this->signInAs($this->user)->withHeader('Idempotency-Key', 'same-attach')->postJson($path, $payload)->assertCreated();
    $this->signInAs($this->user)->withHeader('Idempotency-Key', 'different-attach')->postJson($path, $payload)->assertStatus(409)->assertJsonPath('code', 'duplicate_link');
    expect(CaseClient::count())->toBe(1);
});

it('requires an in-scope replacement before detaching a non-sole primary', function (): void {
    $additional = Client::factory()->create(['owner_user_id' => $this->user->id]);
    $this->signInAs($this->user)->postJson("/api/cases/{$this->case->id}/clients", ['client_id' => $this->client->id, 'relationship_type' => 'primary_client', 'is_primary' => true]);
    $this->signInAs($this->user)->postJson("/api/cases/{$this->case->id}/clients", ['client_id' => $additional->id, 'relationship_type' => 'additional_client', 'is_primary' => false]);

    $this->signInAs($this->user)->deleteJson("/api/cases/{$this->case->id}/clients/{$this->client->id}")->assertStatus(409)->assertJsonPath('code', 'primary_replacement_required');
    $this->signInAs($this->user)->deleteJson("/api/cases/{$this->case->id}/clients/{$this->client->id}", ['replacement_client_id' => $additional->id])->assertNoContent();
    expect(CaseClient::query()->where('client_id', $additional->id)->value('relationship_type'))->toBe('primary_client');
});

it('allows sole detach and rejects every mutation on read-only cases', function (): void {
    $this->signInAs($this->user)->postJson("/api/cases/{$this->case->id}/clients", ['client_id' => $this->client->id, 'relationship_type' => 'primary_client', 'is_primary' => true]);
    $this->signInAs($this->user)->deleteJson("/api/cases/{$this->case->id}/clients/{$this->client->id}")->assertNoContent();
    $this->case->update(['status' => 'closed']);
    $this->signInAs($this->user)->postJson("/api/cases/{$this->case->id}/clients", ['client_id' => $this->client->id, 'relationship_type' => 'primary_client', 'is_primary' => true])->assertStatus(409)->assertJsonPath('code', 'read_only_case');
});

it('applies the CRM mutation throttle to every relationship command', function (): void {
    $routes = app('router')->getRoutes();
    $commands = [
        ['POST', "api/clients/{$this->client->id}/cases"],
        ['DELETE', "api/clients/{$this->client->id}/cases/{$this->case->id}"],
        ['POST', "api/cases/{$this->case->id}/clients"],
        ['DELETE', "api/cases/{$this->case->id}/clients/{$this->client->id}"],
    ];

    foreach ($commands as [$method, $uri]) {
        $route = $routes->match(Request::create($uri, $method));

        expect($route->middleware())->toContain('throttle:crm-mutation');
    }
});

it('resolves the CRM throttle from cached configuration and the Supabase guard', function (): void {
    config(['crm.mutations_per_minute' => 7]);
    $request = Request::create('/api/clients', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
    $request->setUserResolver(fn (?string $guard = null) => $guard === 'supabase' ? $this->user : null);

    $limits = RateLimiter::limiter('crm-mutation')($request);

    expect($limits)->toHaveCount(2)
        ->and($limits[0])->toBeInstanceOf(Limit::class)
        ->and($limits[0]->maxAttempts)->toBe(7)
        ->and($limits[0]->key)->toBe('crm-mutation.user.'.$this->user->getAuthIdentifier());
});
