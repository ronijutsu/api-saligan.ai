<?php

namespace Database\Factories;

use App\Enums\TopUpStatus;
use App\Models\AiTopUp;
use App\Models\Subscription;
use App\Models\SubscriptionWindow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiTopUp>
 */
class AiTopUpFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'subscription_window_id' => SubscriptionWindow::factory(),
            'user_id' => User::factory(),
            'organization_id' => null,
            'packs' => 1,
            'amount_pesos' => 30000,
            'usd_cents' => 125,
            'status' => TopUpStatus::Pending,
            'gateway' => 'paymongo',
            'gateway_payment_intent_id' => 'pi_'.fake()->unique()->bothify('????????????'),
        ];
    }

    /**
     * A pack the gateway has already confirmed and that has granted allowance.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TopUpStatus::Paid,
            'paid_at' => now(),
        ]);
    }
}
