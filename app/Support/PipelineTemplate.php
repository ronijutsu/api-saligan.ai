<?php

namespace App\Support;

use App\Models\PipelineStage;

final class PipelineTemplate
{
    public const DEFAULT_KEY = 'standard_intake_v1';

    /**
     * @return array<int, array{template_key: string, name: string, description: string, stages: array<int, array{name: string, outcome_kind: string, position: int}>}>
     */
    public static function all(): array
    {
        return [
            self::entry('standard_intake_v1', 'Standard intake', 'General-purpose intake from first inquiry through engagement outcome.', PipelineStage::DEFAULT_STAGES),
            self::entry('compact_intake_v1', 'Compact intake', 'Shorter intake path for practices that do not need a separate consultation stage.', [
                ['name' => 'New inquiry', 'outcome_kind' => 'open'],
                ['name' => 'Qualification', 'outcome_kind' => 'open'],
                ['name' => 'Engagement pending', 'outcome_kind' => 'open'],
                ['name' => 'Won', 'outcome_kind' => 'won'],
                ['name' => 'Not proceeding', 'outcome_kind' => 'not_proceeding'],
            ]),
            self::entry('consultation_first_v1', 'Consultation first', 'Intake path that moves a known referral directly toward consultation before qualification.', [
                ['name' => 'New inquiry', 'outcome_kind' => 'open'],
                ['name' => 'Consultation', 'outcome_kind' => 'open'],
                ['name' => 'Qualification', 'outcome_kind' => 'open'],
                ['name' => 'Engagement pending', 'outcome_kind' => 'open'],
                ['name' => 'Won', 'outcome_kind' => 'won'],
                ['name' => 'Not proceeding', 'outcome_kind' => 'not_proceeding'],
            ]),
        ];
    }

    /**
     * @return array{template_key: string, name: string, description: string, stages: array<int, array{name: string, outcome_kind: string, position: int}>}|null
     */
    public static function find(?string $key): ?array
    {
        return collect(self::all())->firstWhere('template_key', $key);
    }

    /**
     * @param  array<int, array{name: string, outcome_kind: string}>  $stages
     * @return array{template_key: string, name: string, description: string, stages: array<int, array{name: string, outcome_kind: string, position: int}>}
     */
    private static function entry(string $key, string $name, string $description, array $stages): array
    {
        return [
            'template_key' => $key,
            'name' => $name,
            'description' => $description,
            'stages' => collect($stages)->values()->map(fn (array $stage, int $position): array => [
                ...$stage,
                'position' => $position,
            ])->all(),
        ];
    }
}
