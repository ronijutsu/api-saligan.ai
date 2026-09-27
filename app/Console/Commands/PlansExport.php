<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Support\PlanFeatures;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('plans:export {--path= : Where to write the JSON (defaults to the landing site\'s data directory)}')]
#[Description('Write the active plans and feature catalogue as JSON for the marketing site to build from')]
class PlansExport extends Command
{
    /**
     * Where the marketing site keeps the generated plans file, relative to the
     * API project root. The repository directory is `landing-batayan`, so the
     * default must not assume the older `landing` name.
     */
    public const DEFAULT_RELATIVE_PATH = '../landing-batayan/src/data/plans.json';

    /**
     * The default export target on the host, where the sibling checkout is
     * reachable. Kept as a method so the path can be asserted without a
     * database, and so the copy-paste guidance below can reuse it.
     */
    public function defaultPath(): string
    {
        return base_path(self::DEFAULT_RELATIVE_PATH);
    }

    /**
     * The marketing site is a separate build with no API call on its critical
     * path, so its pricing table has always been a hand-kept copy of the
     * seeder — and the two had already drifted apart on what Starter included.
     *
     * Exporting the same rows the API serves makes the copy generated rather
     * than remembered: run this after changing the ladder, commit the result,
     * and the marketing table cannot quietly describe a plan nobody sells.
     */
    public function handle(): int
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->where('slug', '!=', Plan::SLUG_BUSINESS)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $plan): array => [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'price' => $plan->price,
                'price_label' => $plan->priceLabel(),
                'price_annual' => $plan->price_annual,
                'price_annual_label' => $plan->priceAnnualLabel(),
                'overage_price' => $plan->overage_price,
                'overage_label' => $plan->overage_price === null ? null : $plan->overageLabel(),
                'ai_budget_label' => $plan->aiBudgetLabel(),
                'ai_usage_multiplier' => $plan->aiUsageMultiplier(),
                'allow_top_ups' => $plan->canBuyTopUps(),
                'topup_label' => $plan->topUpLabel(),
                'included_seats' => $plan->included_seats,
                'seat_price' => $plan->seat_price,
                'seat_price_label' => $plan->seatPriceLabel(),
                'limits' => $plan->limits,
                'features' => $plan->features,
                'contact_sales' => (bool) $plan->contact_sales,
                'annual_only' => (bool) $plan->annual_only,
            ]);

        if ($plans->isEmpty()) {
            $this->error('No active plans found. Seed them first: artisan db:seed --class=PlansSeeder');

            return self::FAILURE;
        }

        // The landing site lives outside this project, and outside the Sail
        // container's mount with it — so the default target is reachable when
        // the command runs on the host and not when it runs in the container.
        // Failing with the workaround printed beats failing with a bare
        // "permission denied" from mkdir.
        $path = (string) ($this->option('path') ?: $this->defaultPath());

        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error("Could not write to {$directory} — it is probably outside this container's mount.");
            $this->line('Write it inside the project and copy it across instead:');
            $this->line('  sail artisan plans:export --path=storage/app/plans.json');
            $this->line('  cp storage/app/plans.json '.self::DEFAULT_RELATIVE_PATH);

            return self::FAILURE;
        }

        file_put_contents($path, json_encode([
            'plans' => $plans,
            'features' => PlanFeatures::catalogue(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

        $this->info("Wrote {$plans->count()} plans to {$path}");

        return self::SUCCESS;
    }
}
