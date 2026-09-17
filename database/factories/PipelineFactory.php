<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Pipeline> */
class PipelineFactory extends Factory
{
    protected $model = Pipeline::class;

    public function definition(): array
    {
        return [
            'organization_id' => null,
            'owner_user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'description' => null,
            'is_default' => false,
            'archived_at' => null,
        ];
    }

    public function forOrganization(Organization|int $organization, User $owner): static
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

    public function withDefaultStages(): static
    {
        return $this->afterCreating(function (Pipeline $pipeline): void {
            foreach (PipelineStage::DEFAULT_STAGES as $position => $stage) {
                $pipeline->allStages()->create($stage + ['position' => $position]);
            }
        });
    }
}
