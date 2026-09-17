<?php

use App\Models\Plan;
use App\Support\PlanFeatures;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Ensure every currently sold tier carries the CRM capability.
     *
     * `PlansSeeder` already writes the correct feature set, but existing
     * databases need the capability appended without requiring a re-seed.
     */
    public function up(): void
    {
        foreach ([Plan::SLUG_STANDARD, Plan::SLUG_PRO, Plan::SLUG_FIRM] as $slug) {
            $plan = Plan::query()->where('slug', $slug)->first();

            if ($plan === null) {
                continue;
            }

            $features = $plan->features ?? [];

            if (! in_array(PlanFeatures::CRM, $features, true)) {
                $features[] = PlanFeatures::CRM;

                // Keep the feature order stable for the pricing table.
                $plan->forceFill(['features' => array_values($features)])->save();
            }
        }
    }

    public function down(): void
    {
        foreach ([Plan::SLUG_STANDARD, Plan::SLUG_PRO, Plan::SLUG_FIRM] as $slug) {
            $plan = Plan::query()->where('slug', $slug)->first();

            if ($plan === null) {
                continue;
            }

            $features = array_values(array_filter(
                $plan->features ?? [],
                fn (string $feature): bool => $feature !== PlanFeatures::CRM,
            ));

            $plan->forceFill(['features' => $features])->save();
        }
    }
};
