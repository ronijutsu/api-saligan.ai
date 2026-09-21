<?php

use App\Models\Plan;
use App\Support\PlanFeatures;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Carry the PDF capability onto the free trial plan.
     *
     * `PlansSeeder` already writes the correct feature set, but existing
     * databases need the capability appended without requiring a re-seed.
     * Paid tiers are untouched; the trial's documents_uploaded cap remains
     * the abuse guard.
     */
    public function up(): void
    {
        $plan = Plan::query()->where('slug', Plan::SLUG_TRIAL)->first();

        if ($plan === null) {
            return;
        }

        $features = $plan->features ?? [];

        if (! in_array(PlanFeatures::PDF_DOCUMENTS, $features, true)) {
            $features[] = PlanFeatures::PDF_DOCUMENTS;

            $plan->forceFill(['features' => array_values($features)])->save();
        }
    }

    public function down(): void
    {
        $plan = Plan::query()->where('slug', Plan::SLUG_TRIAL)->first();

        if ($plan === null) {
            return;
        }

        $features = array_values(array_filter(
            $plan->features ?? [],
            fn (string $feature): bool => $feature !== PlanFeatures::PDF_DOCUMENTS,
        ));

        $plan->forceFill(['features' => $features])->save();
    }
};
