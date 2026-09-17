<?php

namespace Database\Factories;

use App\Models\PipelineItem;
use App\Models\PipelineStage;
use App\Models\PipelineStageChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PipelineStageChange> */
class PipelineStageChangeFactory extends Factory
{
    protected $model = PipelineStageChange::class;

    public function definition(): array
    {
        return [
            'pipeline_item_id' => PipelineItem::factory(),
            'previous_stage_id' => null,
            'new_stage_id' => PipelineStage::factory(),
            'actor_user_id' => User::factory(),
            'request_id' => null,
        ];
    }
}
