<?php

namespace Database\Factories;

use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PipelineStage> */
class PipelineStageFactory extends Factory
{
    protected $model = PipelineStage::class;

    public function definition(): array
    {
        return [
            'pipeline_id' => Pipeline::factory(),
            'name' => fake()->unique()->words(2, true),
            'position' => 0,
            'outcome_kind' => 'open',
            'archived_at' => null,
        ];
    }

    public function forPipeline(Pipeline $pipeline, int $position = 0): static
    {
        return $this->state([
            'pipeline_id' => $pipeline->id,
            'position' => $position,
        ]);
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now()]);
    }

    public function terminal(string $outcomeKind = 'won'): static
    {
        return $this->state(['outcome_kind' => $outcomeKind]);
    }
}
