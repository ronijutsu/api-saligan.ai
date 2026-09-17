<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Pipeline;
use App\Models\PipelineItem;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PipelineItem> */
class PipelineItemFactory extends Factory
{
    protected $model = PipelineItem::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'pipeline_id' => Pipeline::factory(),
            'stage_id' => PipelineStage::factory(),
            'owner_user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'source' => fake()->optional()->word(),
            'note' => null,
            'next_action_at' => null,
            'archived_at' => null,
        ];
    }

    public function forPipeline(Pipeline $pipeline, PipelineStage $stage): static
    {
        return $this->state([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);
    }

    public function forClient(Client $client): static
    {
        return $this->state([
            'client_id' => $client->id,
            'owner_user_id' => $client->owner_user_id,
        ]);
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now()]);
    }
}
