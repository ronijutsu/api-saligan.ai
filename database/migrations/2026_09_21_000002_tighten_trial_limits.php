<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Tighten the free trial allowance: 12→5 documents, 60→30 messages.
     *
     * `PlansSeeder` already writes the correct limits, but existing databases
     * need the trial row updated without requiring a re-seed. Only the two
     * count caps move; the $0.52 AI budget and everything else stay as they
     * were, and paid tiers are untouched.
     */
    public function up(): void
    {
        $plan = Plan::query()->where('slug', Plan::SLUG_TRIAL)->first();

        if ($plan === null) {
            return;
        }

        $limits = $plan->limits ?? [];
        $limits['documents_uploaded'] = 5;
        $limits['messages_used'] = 30;

        $plan->forceFill(['limits' => $limits])->save();
    }

    public function down(): void
    {
        $plan = Plan::query()->where('slug', Plan::SLUG_TRIAL)->first();

        if ($plan === null) {
            return;
        }

        $limits = $plan->limits ?? [];
        $limits['documents_uploaded'] = 12;
        $limits['messages_used'] = 60;

        $plan->forceFill(['limits' => $limits])->save();
    }
};
