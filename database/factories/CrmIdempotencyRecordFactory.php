<?php

namespace Database\Factories;

use App\Models\CrmIdempotencyRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CrmIdempotencyRecord> */
class CrmIdempotencyRecordFactory extends Factory
{
    protected $model = CrmIdempotencyRecord::class;

    public function definition(): array
    {
        return [
            'scope_key' => 'user:'.$this->faker->numberBetween(1, 1000).'|solo',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', (string) Str::uuid()),
            'response_status' => null,
            'response_headers' => null,
            'response_body' => null,
            'completed_at' => null,
            'expires_at' => now()->addDay(),
        ];
    }
}
