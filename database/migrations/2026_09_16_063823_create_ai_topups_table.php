<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per purchased AI top-up pack (ADR-010).
     *
     * The row is the purchase, not the allowance: it records what was charged
     * and what it granted, and it exists from before the customer is redirected
     * to the gateway so a returned webhook has something to credit against. The
     * allowance itself lives on `subscription_windows.topup_usd` — this table
     * only ever explains where that number came from.
     */
    public function up(): void
    {
        Schema::create('ai_top_ups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_window_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('packs')->default(1);
            $table->unsignedBigInteger('amount_pesos');
            $table->unsignedInteger('usd_cents');

            $table->string('status', 20)->default('pending');
            $table->string('gateway', 20)->default('paymongo');
            $table->string('gateway_payment_intent_id', 100)->nullable();
            $table->string('gateway_payment_id', 100)->nullable();
            $table->text('checkout_url')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('subscription_id');
            $table->index('subscription_window_id');
            $table->index(['status', 'created_at']);
            // The webhook arrives with only the gateway's own identifier, so
            // that lookup has to be indexed.
            $table->index('gateway_payment_intent_id');
            $table->index('gateway_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_top_ups');
    }
};
