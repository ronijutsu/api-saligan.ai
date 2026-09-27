<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['case_id', 'client_id', 'relationship_type', 'is_primary', 'attached_by_user_id'])]
class CaseClient extends Pivot
{
    protected $table = 'case_client';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function attachedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attached_by_user_id');
    }

    public function relationshipLabel(): string
    {
        return $this->relationship_type === 'primary_client' ? 'Primary client' : 'Additional client';
    }
}
