<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The columns behind prepaid AI top-ups (ADR-010).
     *
     * Three separate concerns, deliberately kept apart:
     *  - `plans` decides whether a tier may buy extra usage at all, and at what
     *    price (admin-controlled).
     *  - `subscriptions` holds the customer's own switch and their monthly
     *    spending ceiling on packs.
     *  - `subscription_windows` holds the allowance a pack actually granted, so
     *    the gate stays a single comparison and the extra usage expires with the
     *    window it was bought for.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Admin gate: a tier that cannot top up keeps the old dead end.
            $table->boolean('allow_top_ups')->default(false);

            // Provisional pack pricing — see ADR-010. `topup_price` is in the
            // same minor units as `price`, and `topup_usd_cents` is the
            // allowance the pack grants, in the same unit as
            // `ai_budget_usd_cents`.
            $table->unsignedBigInteger('topup_price')->nullable();
            $table->unsignedInteger('topup_usd_cents')->nullable();

            // An optional admin guard on top of the customer's own cap: the most
            // packs one window may hold. Null means no plan-level limit.
            $table->unsignedSmallInteger('topup_max_per_window')->nullable();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // The customer's switch. Off by default: nobody buys extra usage
            // without asking for it first.
            $table->boolean('topup_enabled')->default(false);

            // The customer's own monthly ceiling on top-up spend, in minor
            // units. Null means they have not set one.
            $table->unsignedBigInteger('topup_cap_pesos')->nullable();
        });

        Schema::table('subscription_windows', function (Blueprint $table) {
            // The allowance bought for this window. Expires with the window.
            $table->decimal('topup_usd', 10, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['allow_top_ups', 'topup_price', 'topup_usd_cents', 'topup_max_per_window']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['topup_enabled', 'topup_cap_pesos']);
        });

        Schema::table('subscription_windows', function (Blueprint $table) {
            $table->dropColumn('topup_usd');
        });
    }
};
