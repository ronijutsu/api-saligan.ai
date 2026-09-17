<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory(),
            'organization_id' => null,
            'client_type' => 'person',
            'display_name' => fake()->name(),
            'contact' => ['email' => fake()->safeEmail(), 'phone' => fake()->phoneNumber()],
            'notes' => null,
            'lifecycle' => 'prospect',
            'archived_at' => null,
        ];
    }

    public function forOrganization(Organization|string $organization, User $owner): static
    {
        return $this->state([
            'organization_id' => $organization instanceof Organization ? $organization->id : $organization,
            'owner_user_id' => $owner->id,
        ]);
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now()]);
    }
}
