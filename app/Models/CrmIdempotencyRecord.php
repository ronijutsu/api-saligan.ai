<?php

namespace App\Models;

use Database\Factories\CrmIdempotencyRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;

#[Fillable([
    'id',
    'scope_key',
    'idempotency_key',
    'request_fingerprint',
    'response_status',
    'response_headers',
    'response_body',
    'completed_at',
    'expires_at',
])]
#[Hidden(['scope_key', 'idempotency_key', 'request_fingerprint', 'response_body'])]
class CrmIdempotencyRecord extends Model
{
    /** @use HasFactory<CrmIdempotencyRecordFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_headers' => 'array',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function replay(): Response
    {
        return new Response(
            Crypt::decryptString((string) $this->response_body),
            (int) $this->response_status,
            $this->response_headers ?? [],
        );
    }
}
