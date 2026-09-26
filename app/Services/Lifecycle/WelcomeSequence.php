<?php

namespace App\Services\Lifecycle;

use App\Enums\MessageRole;
use App\Mail\WelcomeSequenceMail;
use App\Models\LifecycleEmail;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The five-email welcome sequence for trial users.
 *
 * Each step is due a number of days after the trial started. A step whose goal
 * the user has already reached (e.g. "draft a document" after they drafted
 * one) is recorded as skipped rather than sent. The sequence stops by itself
 * when the trial converts or lapses, because only live trials are swept.
 *
 * It ends on day 9 on purpose: the existing TrialEnding warning (3 days left)
 * owns the "your trial is ending" message.
 */
class WelcomeSequence
{
    public const SEQUENCE = 'welcome';

    /** step => days after the trial started */
    public const STEP_DAYS = [1 => 0, 2 => 1, 3 => 3, 4 => 6, 5 => 9];

    /** Minimum gap between two emails to the same person. */
    private const MIN_HOURS_BETWEEN = 20;

    /**
     * Send whatever step is due for each live trial.
     *
     * @return int the number of emails sent
     */
    public function sweep(): int
    {
        $sent = 0;

        $oldestRelevantStart = now()->subDays(max(self::STEP_DAYS) + 1);
        $since = config('saligan.welcome_sequence.since');

        Subscription::query()
            ->where('status', Subscription::STATUS_TRIALING)
            ->where('trial_ends_at', '>', now())
            ->where('created_at', '>=', $oldestRelevantStart)
            ->when($since, fn ($query) => $query->where('created_at', '>=', Carbon::parse($since)))
            ->with(['user.lawyerProfile'])
            ->chunkById(100, function ($subscriptions) use (&$sent): void {
                foreach ($subscriptions as $subscription) {
                    if ($this->process($subscription)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    /**
     * Handle one trial; returns whether an email went out.
     */
    public function process(Subscription $subscription): bool
    {
        $user = $subscription->user;

        if ($user === null || $user->lifecycle_emails_opted_out_at !== null || $user->lawyerProfile !== null) {
            return false;
        }

        $step = $this->dueStep($subscription);

        if ($step === null || $this->alreadyHandled($user, $step) || $this->sentRecently($user)) {
            return false;
        }

        if ($this->goalReached($user, $step)) {
            $this->record($user, $step, LifecycleEmail::OUTCOME_SKIPPED);

            return false;
        }

        // Recorded before sending, as TrialWarner does: a mail failure must not
        // leave the step eligible on every later sweep. One lost email is a
        // smaller problem than emailing in a loop.
        if (! $this->record($user, $step, LifecycleEmail::OUTCOME_SENT)) {
            return false;
        }

        try {
            Mail::to($user)->queue(new WelcomeSequenceMail($user, $step, $subscription->trialDaysRemaining()));
        } catch (\Throwable $e) {
            Log::warning('Welcome sequence email failed.', [
                'user_id' => $user->id,
                'step' => $step,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * The latest step whose day has arrived. Earlier steps that were missed
     * (a scheduler outage, a trial started before the sequence existed) are
     * not sent late — the most relevant message wins.
     */
    public function dueStep(Subscription $subscription): ?int
    {
        $daysIn = (int) floor($subscription->created_at->diffInDays(now()));
        $due = null;

        foreach (self::STEP_DAYS as $step => $day) {
            if ($daysIn >= $day) {
                $due = $step;
            }
        }

        return $due;
    }

    /**
     * Whether the user already did what this step's email asks them to do.
     */
    public function goalReached(User $user, int $step): bool
    {
        return match ($step) {
            2 => $this->userMessages($user)->count() >= 3,
            3 => $this->userMessages($user, role: null)->whereNotNull('metadata->letter_draft')->exists(),
            4 => $user->documents()->exists() && $user->todos()->exists(),
            default => false,
        };
    }

    private function userMessages(User $user, ?MessageRole $role = MessageRole::User)
    {
        return Message::query()
            ->whereHas('conversation', fn ($query) => $query->where('user_id', $user->id))
            ->when($role, fn ($query) => $query->where('role', $role));
    }

    private function alreadyHandled(User $user, int $step): bool
    {
        return LifecycleEmail::query()
            ->where('user_id', $user->id)
            ->where('sequence', self::SEQUENCE)
            ->where('step', $step)
            ->exists();
    }

    private function sentRecently(User $user): bool
    {
        return LifecycleEmail::query()
            ->where('user_id', $user->id)
            ->where('sequence', self::SEQUENCE)
            ->where('outcome', LifecycleEmail::OUTCOME_SENT)
            ->where('created_at', '>', now()->subHours(self::MIN_HOURS_BETWEEN))
            ->exists();
    }

    /**
     * Returns false if another sweep recorded this step first (the unique key
     * is the real guard against two overlapping runs double-sending).
     */
    private function record(User $user, int $step, string $outcome): bool
    {
        try {
            LifecycleEmail::create([
                'user_id' => $user->id,
                'sequence' => self::SEQUENCE,
                'step' => $step,
                'outcome' => $outcome,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
