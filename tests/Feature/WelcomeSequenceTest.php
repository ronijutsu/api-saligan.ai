<?php

use App\Enums\MessageRole;
use App\Mail\WelcomeSequenceMail;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\LawyerProfile;
use App\Models\LifecycleEmail;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Todo;
use App\Models\User;
use App\Services\Lifecycle\WelcomeSequence;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Mail::fake();

    config([
        'saligan.welcome_sequence.enabled' => true,
        'saligan.welcome_sequence.since' => null,
        'saligan.welcome_sequence.signature' => 'The Batayan team',
        'app.frontend_url' => 'https://app.batayan.co',
    ]);

    $this->user = User::factory()->create(['name' => 'Maria Santos']);
});

/**
 * A live 14-day trial for the user that started the given number of days ago.
 */
function trialStarted(User $user, int $daysAgo): Subscription
{
    $subscription = Subscription::factory()->create([
        'user_id' => $user->id,
        'status' => Subscription::STATUS_TRIALING,
        'trial_ends_at' => now()->subDays($daysAgo)->addDays(14),
        'price_per_seat' => 0,
    ]);

    $subscription->forceFill(['created_at' => now()->subDays($daysAgo)->subMinutes(5)])->save();

    return $subscription;
}

function sweepWelcome(): int
{
    return app(WelcomeSequence::class)->sweep();
}

it('sends the welcome email on the day the trial starts', function () {
    trialStarted($this->user, 0);

    expect(sweepWelcome())->toBe(1);

    Mail::assertQueued(WelcomeSequenceMail::class, fn (WelcomeSequenceMail $mail) => $mail->step === 1
        && $mail->hasTo($this->user->email));
});

it('sends each step once, however often the sweep runs', function () {
    trialStarted($this->user, 0);

    expect(sweepWelcome())->toBe(1)
        ->and(sweepWelcome())->toBe(0)
        ->and(sweepWelcome())->toBe(0);

    Mail::assertQueued(WelcomeSequenceMail::class, 1);
    expect(LifecycleEmail::where('user_id', $this->user->id)->count())->toBe(1);
});

it('walks through the steps as the trial ages', function () {
    trialStarted($this->user, 0);
    sweepWelcome();

    // [days to advance, step now due]: the trial reaches day 1, 3, 6, then 9.
    foreach ([[1, 2], [2, 3], [3, 4], [3, 5]] as [$advance, $step]) {
        $this->travel($advance)->days();
        expect(sweepWelcome())->toBe(1);
        Mail::assertQueued(WelcomeSequenceMail::class, fn (WelcomeSequenceMail $mail) => $mail->step === $step);
    }

    Mail::assertQueued(WelcomeSequenceMail::class, 5);
});

it('keeps at least 20 hours between emails', function () {
    $subscription = trialStarted($this->user, 1);
    LifecycleEmail::create([
        'user_id' => $this->user->id,
        'sequence' => WelcomeSequence::SEQUENCE,
        'step' => 1,
        'outcome' => LifecycleEmail::OUTCOME_SENT,
    ]);

    expect(sweepWelcome())->toBe(0);
    Mail::assertNothingQueued();

    $this->travel(21)->hours();

    expect(app(WelcomeSequence::class)->process($subscription->fresh()))->toBeTrue();
});

it('sends only the latest due step when earlier ones were missed', function () {
    trialStarted($this->user, 6);

    expect(sweepWelcome())->toBe(1);

    Mail::assertQueued(WelcomeSequenceMail::class, fn (WelcomeSequenceMail $mail) => $mail->step === 4);
    Mail::assertQueued(WelcomeSequenceMail::class, 1);
});

it('skips the first-question email for users who already chat', function () {
    trialStarted($this->user, 1);
    $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);
    Message::factory()->count(3)->create(['conversation_id' => $conversation->id, 'role' => MessageRole::User]);

    expect(sweepWelcome())->toBe(0);

    Mail::assertNothingQueued();
    expect(LifecycleEmail::where('step', 2)->value('outcome'))->toBe(LifecycleEmail::OUTCOME_SKIPPED);
});

it('skips the drafting email for users who already drafted a document', function () {
    trialStarted($this->user, 3);
    $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'metadata' => ['letter_draft' => ['title' => 'Employment contract', 'doc' => []]],
    ]);

    expect(sweepWelcome())->toBe(0);

    Mail::assertNothingQueued();
});

it('skips the deadlines email only when the user has both documents and to-dos', function () {
    trialStarted($this->user, 6);
    Document::factory()->create(['user_id' => $this->user->id]);

    expect(sweepWelcome())->toBe(1);

    $other = User::factory()->create();
    trialStarted($other, 6);
    Document::factory()->create(['user_id' => $other->id]);
    $conversation = Conversation::factory()->create(['user_id' => $other->id]);
    Todo::factory()->create(['conversation_id' => $conversation->id]);

    expect(sweepWelcome())->toBe(0);
    Mail::assertNotQueued(WelcomeSequenceMail::class, fn (WelcomeSequenceMail $mail) => $mail->user->is($other));
});

it('leaves paid, lapsed, lawyer, and unsubscribed accounts alone', function () {
    trialStarted($this->user, 0)->forceFill(['status' => Subscription::STATUS_ACTIVE])->save();

    $lapsed = User::factory()->create();
    trialStarted($lapsed, 0)->forceFill(['trial_ends_at' => now()->subMinute()])->save();

    $lawyer = User::factory()->create();
    LawyerProfile::factory()->create(['user_id' => $lawyer->id]);
    trialStarted($lawyer, 0);

    $optedOut = User::factory()->create(['lifecycle_emails_opted_out_at' => now()]);
    trialStarted($optedOut, 0);

    expect(sweepWelcome())->toBe(0);

    Mail::assertNothingQueued();
});

it('ignores trials that started before the configured cut-off', function () {
    config(['saligan.welcome_sequence.since' => now()->subDays(2)->toDateTimeString()]);
    trialStarted($this->user, 3);

    expect(sweepWelcome())->toBe(0);
});

it('does nothing from the command while disabled', function () {
    config(['saligan.welcome_sequence.enabled' => false]);
    trialStarted($this->user, 0);

    $this->artisan('emails:welcome-sequence')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('renders tracked links, the trial countdown, and an unsubscribe link', function () {
    $mail = new WelcomeSequenceMail($this->user, 5, 5);

    $mail->assertHasSubject('A licensed lawyer, without the retainer');
    $mail->assertSeeInHtml('Hi Maria,');
    $mail->assertSeeInHtml('https://app.batayan.co/vetting?utm_source=email&amp;utm_medium=lifecycle&amp;utm_campaign=welcome&amp;utm_content=e5', false);
    $mail->assertSeeInHtml('Your trial ends in 5 days.');
    $mail->assertSeeInHtml('Unsubscribe');

    expect($mail->headers()->text['List-Unsubscribe'])->toContain('/api/email/unsubscribe/'.$this->user->id);
});

it('unsubscribes through the signed link', function () {
    $url = URL::signedRoute('email.unsubscribe', ['user' => $this->user->id]);

    $this->get($url)->assertOk()->assertSee('You\'re unsubscribed', false);

    expect($this->user->fresh()->lifecycle_emails_opted_out_at)->not->toBeNull();
});

it('accepts the one-click unsubscribe POST from mail clients', function () {
    $url = URL::signedRoute('email.unsubscribe', ['user' => $this->user->id]);

    $this->post($url)->assertNoContent();

    expect($this->user->fresh()->lifecycle_emails_opted_out_at)->not->toBeNull();
});

it('refuses an unsigned or tampered unsubscribe link', function () {
    $this->get("/api/email/unsubscribe/{$this->user->id}")->assertForbidden();

    $other = User::factory()->create();
    $url = URL::signedRoute('email.unsubscribe', ['user' => $this->user->id]);
    $this->get(str_replace("/unsubscribe/{$this->user->id}", "/unsubscribe/{$other->id}", $url))->assertForbidden();

    expect($other->fresh()->lifecycle_emails_opted_out_at)->toBeNull();
});
