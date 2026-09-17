<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Auth\SupabaseJwtService;
use Illuminate\Support\Str;

it('requires a bearer token for the user endpoint', function () {
    $this->getJson('/api/user')->assertStatus(401);
});

it('answers a browser request without a token with 401, not a missing login route', function () {
    // A browser sends Accept: text/html, so the guest path is taken rather than
    // the JSON one. This app has no login page, and the default guest redirect
    // pointed at a `login` route that does not exist — which surfaced as a 500
    // RouteNotFoundException instead of the 401 the caller should have seen.
    $this->get('/api/user')->assertStatus(401);
});

it('rejects an invalid or malformed bearer token', function () {
    $this->withHeader('Authorization', 'Bearer not-a-valid-token')
        ->getJson('/api/user')
        ->assertStatus(401);
});

it('imports a new Supabase user on first request', function () {
    $uid = (string) Str::uuid();

    $token = app(SupabaseJwtService::class)->mintToken($uid, 'jane@example.com', [
        'full_name' => 'Jane Doe',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/user')
        ->assertSuccessful()
        ->assertJsonPath('data.email', 'jane@example.com')
        ->assertJsonPath('data.name', 'Jane Doe');

    $this->assertDatabaseHas('users', [
        'email' => 'jane@example.com',
        'supabase_uid' => $uid,
        'is_admin' => false,
    ]);
});

it('does not start any subscription when it imports a new Supabase user', function () {
    // The trial starts only when the user picks it on /choose-plan
    // (POST /api/subscription/trial). Importing must leave the account
    // subscription-less even when the trial plan exists, or the new user
    // skips the plan selector straight to the dashboard.
    Plan::factory()->trial()->create();
    $uid = (string) Str::uuid();

    $token = app(SupabaseJwtService::class)->mintToken($uid, 'trial@example.com');

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/user')
        ->assertSuccessful();

    $user = User::where('supabase_uid', $uid)->firstOrFail();

    expect($user->subscription)->toBeNull()
        ->and(Subscription::where('user_id', $user->id)->count())->toBe(0);
});

it('does not allow importing the admin flag from token claims', function () {
    $uid = (string) Str::uuid();

    $token = app(SupabaseJwtService::class)->mintToken($uid, 'jane@example.com', [
        'full_name' => 'Jane Doe',
        'is_admin' => true,
    ]);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/user')
        ->assertSuccessful();

    expect(User::where('supabase_uid', $uid)->first()->is_admin)->toBeFalse();
});

it('resolves an existing user by supabase uid', function () {
    $user = User::factory()->create(['supabase_uid' => 'existing-uid']);

    $token = app(SupabaseJwtService::class)->mintToken('existing-uid', $user->email);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);

    expect(User::count())->toBe(1);
});

it('links a pre-Supabase account by email', function () {
    $user = User::factory()->create();
    $uid = (string) Str::uuid();

    $token = app(SupabaseJwtService::class)->mintToken($uid, $user->email);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);

    expect($user->fresh()->supabase_uid)->toBe($uid);

    expect(User::count())->toBe(1);
});

it('requires both a uid and an email in the token', function () {
    $uid = (string) Str::uuid();

    $token = app(SupabaseJwtService::class)->mintToken($uid, '');

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/user')
        ->assertStatus(401);
});

it('removes the legacy password login endpoint', function () {
    $this->postJson('/api/login', [
        'email' => 'jane@example.com',
        'password' => 'password123',
    ])->assertNotFound();
});

it('signs in an existing user via the helper', function () {
    $user = User::factory()->create();

    $this->signInAs($user);

    $this->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email);
});
