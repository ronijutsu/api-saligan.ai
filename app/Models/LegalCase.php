<?php

namespace App\Models;

use App\Enums\MessageRole;
use App\Services\Cases\CaseDigestService;
use Database\Factories\LegalCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'organization_id',
    'title',
    'case_type',
    'reference',
    'priority',
    'status',
    'closed_at',
    'retention_status',
    'description',
    'digest',
    'digest_generated_at',
    'digest_source_hash',
    'related_parties',
    'due_date',
    'deadline_reminded_at',
    'deadline_reminded_due_date',
    'tags',
    'default_template_id',
    'archived_at',
])]
class LegalCase extends Model
{
    /** @use HasFactory<LegalCaseFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'cases';

    protected static function booted(): void
    {
        static::saved(function (LegalCase $case): void {
            $digestFields = ['digest', 'digest_generated_at', 'digest_source_hash'];
            $changed = array_keys($case->getChanges());

            if ($changed !== [] && count(array_diff($changed, $digestFields)) === 0) {
                return;
            }

            app(CaseDigestService::class)->queue($case);
        });
    }

    /**
     * Retention status options for matter memory governance.
     */
    public const RETENTION_STATUSES = [
        'active',
        'closed',
        'closed-pending-deletion',
        'on-legal-hold',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'related_parties' => 'array',
            'tags' => 'array',
            'digest_generated_at' => 'datetime',
            'due_date' => 'date',
            'deadline_reminded_at' => 'datetime',
            'deadline_reminded_due_date' => 'date',
            'closed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The user who owns this case. Ownership is fixed at creation and is what
     * billing counts against; sharing the work out never moves it.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The firm this case belongs to, fixed from the owner's organization at
     * creation. Null for a solo account, which is what makes a case
     * unshareable rather than shareable-with-nobody.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Reads better than `user()` at every call site that means "the owner",
     * now that a case has other people on it too.
     */
    public function owner(): BelongsTo
    {
        return $this->user();
    }

    /**
     * The colleagues assigned to work this case alongside the owner. The owner
     * is deliberately not a row here: they hold the case by `user_id`, and
     * duplicating that would let the two disagree.
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'case_user', 'case_id', 'user_id')
            ->withPivot('assigned_by')
            ->withTimestamps()
            ->orderBy('users.name');
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'case_client', 'case_id', 'client_id')
            ->using(CaseClient::class)
            ->withPivot(['relationship_type', 'is_primary', 'attached_by_user_id'])
            ->withTimestamps();
    }

    public function caseClients(): HasMany
    {
        return $this->hasMany(CaseClient::class, 'case_id');
    }

    /**
     * Whether the user holds this case, either as its owner or as someone
     * assigned to it. The single question every access check asks.
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }

        return $this->assignees()->whereKey($user->id)->exists();
    }

    /**
     * Whether the matter is finished. A closed or archived case still reads,
     * exports, and reopens, but nothing about it may be changed — including
     * who is on it, which stays as the record of who actually worked it.
     */
    public function isReadOnly(): bool
    {
        return $this->archived_at !== null || $this->status === 'closed';
    }

    /**
     * Scope to the cases a user may open: the ones they own, plus the ones
     * they have been assigned to.
     *
     * @param  Builder<LegalCase>  $query
     */
    public function scopeVisibleTo($query, User $user): void
    {
        $query->where(function ($scoped) use ($user) {
            $scoped->where('cases.user_id', $user->id)
                ->orWhereHas('assignees', fn ($assignee) => $assignee->whereKey($user->id));
        });
    }

    /**
     * The conversation threads scoped to this case, one per purpose.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'case_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * The first (default) conversation thread scoped to this case.
     */
    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class, 'case_id');
    }

    /**
     * The default template associated with this case, if any.
     */
    public function defaultTemplate(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'default_template_id');
    }

    /**
     * The messages in this case (through its conversation).
     */
    public function messages(): HasManyThrough
    {
        return $this->hasManyThrough(Message::class, Conversation::class, 'case_id', 'conversation_id', 'id', 'id');
    }

    /**
     * The documents attached to this case.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'case_id');
    }

    /**
     * The tasks belonging to this case (through its conversation).
     */
    public function tasks(): HasManyThrough
    {
        return $this->hasManyThrough(Todo::class, Conversation::class, 'case_id', 'conversation_id', 'id', 'id')
            ->orderBy('order')->orderBy('created_at');
    }

    /**
     * The letters drafted within this case (through its conversation), each
     * carrying a Tiptap letter in its metadata.
     */
    public function generatedDocuments(): HasManyThrough
    {
        return $this->hasManyThrough(Message::class, Conversation::class, 'case_id', 'conversation_id', 'id', 'id')
            ->where('role', MessageRole::Assistant)
            ->whereNotNull('metadata->letter_draft');
    }

    /**
     * Scope to include only active (non-archived) cases.
     */
    public function scopeActive($query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Scope to include only archived cases.
     */
    public function scopeArchived($query): void
    {
        $query->whereNotNull('archived_at');
    }

    /**
     * Scope to include only archived cases (explicit name for clarity in the controller).
     */
    public function scopeOnlyArchived($query): void
    {
        $query->whereNotNull('archived_at');
    }
}
