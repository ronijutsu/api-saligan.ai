<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LifecycleEmail extends Model
{
    public const OUTCOME_SENT = 'sent';

    public const OUTCOME_SKIPPED = 'skipped';

    protected $fillable = [
        'user_id',
        'sequence',
        'step',
        'outcome',
    ];

    protected function casts(): array
    {
        return [
            'step' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
