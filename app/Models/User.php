<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\IntegrationProvider;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'supabase_uid',
    'name',
    'email',
    'password',
    'organization_id',
    'org_role',
    'org_status',
    'kyc_role',
    'kyc_role_other',
    'kyc_use_case',
    'kyc_use_case_other',
    'kyc_document_types',
    'kyc_experience_level',
    'kyc_completed_at',
    'tour_completed_at',
    'terms_accepted_at',
    'terms_version',
    'marketing_opt_in',
    'last_used_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ORG_ROLE_OWNER = 'owner';

    public const ORG_ROLE_ADMIN = 'admin';

    public const ORG_ROLE_MEMBER = 'member';

    public const ORG_STATUS_ACTIVE = 'active';

    public const ORG_STATUS_INVITED = 'invited';

    public const ORG_STATUS_SUSPENDED = 'suspended';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'kyc_completed_at' => 'datetime',
            'tour_completed_at' => 'datetime',
            'lifecycle_emails_opted_out_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'marketing_opt_in' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Whether the user has completed the optional onboarding profile
     * (role + primary use case). A null completed-at timestamp means the
     * user skipped onboarding, so no profile-based calibration applies.
     */
    public function hasKycProfile(): bool
    {
        return $this->kyc_completed_at !== null;
    }

    /**
     * Record that the user just used the app. The login screen shows the
     * previous value ("last used 3 days ago"), so this must never be cleared
     * by anything other than an actual visit.
     */
    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }

    /**
     * Whether the user has accepted the current Terms of Service and Privacy Policy.
     */
    public function hasAcceptedTerms(): bool
    {
        return $this->terms_accepted_at !== null
            && $this->terms_version === LegalDocument::currentVersion();
    }

    /**
     * The organization this user belongs to, if any.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Whether the user belongs to an organization.
     */
    public function hasOrganization(): bool
    {
        return $this->organization_id !== null;
    }

    /**
     * Whether the user owns their organization.
     */
    public function isOrganizationOwner(): bool
    {
        return $this->org_role === self::ORG_ROLE_OWNER;
    }

    /**
     * Whether the user can manage the organization's members, invites, and seats.
     */
    public function canManageOrganization(): bool
    {
        return in_array($this->org_role, [self::ORG_ROLE_OWNER, self::ORG_ROLE_ADMIN], true);
    }

    /**
     * Whether the user's organization membership is currently active.
     */
    public function hasActiveMembership(): bool
    {
        return $this->hasOrganization() && $this->org_status === self::ORG_STATUS_ACTIVE;
    }

    /**
     * Whether an admin has suspended this user's membership.
     *
     * A suspended member keeps their row, their organization, and — because
     * the subscription resolves through that organization — everything the
     * plan pays for. Suspension is therefore a check of its own rather than a
     * side effect of billing: without it, the only thing revoked is the
     * ability to appear in member pickers.
     */
    public function isSuspended(): bool
    {
        return $this->org_status === self::ORG_STATUS_SUSPENDED;
    }

    /**
     * The verified-lawyer profile of this user, if they registered as one.
     */
    public function lawyerProfile(): HasOne
    {
        return $this->hasOne(LawyerProfile::class);
    }

    /**
     * The vetting requests this user submitted.
     */
    public function vettingRequests(): HasMany
    {
        return $this->hasMany(VettingRequest::class, 'submitter_id');
    }

    /**
     * The documents uploaded by this user.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * The conversations started by this user.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * The cases owned by this user. Ownership is what plan limits and billing
     * count, so this deliberately excludes cases they are merely assigned to.
     */
    public function cases(): HasMany
    {
        return $this->hasMany(LegalCase::class);
    }

    /**
     * The cases this user has been assigned to by someone else. Owned cases
     * are not in here; use LegalCase::visibleTo() for "everything I can open".
     */
    public function assignedCases(): BelongsToMany
    {
        return $this->belongsToMany(LegalCase::class, 'case_user', 'user_id', 'case_id')
            ->withTimestamps();
    }

    /**
     * The custom letter templates saved by this user.
     */
    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    /**
     * The todos across all conversations.
     */
    public function todos(): HasManyThrough
    {
        return $this->hasManyThrough(Todo::class, Conversation::class);
    }

    /**
     * The flagged caveats and gaps across all conversations.
     */
    public function advisories(): HasManyThrough
    {
        return $this->hasManyThrough(Advisory::class, Conversation::class);
    }

    /**
     * The billing subscription that governs this user's access. Subscriptions
     * belong to the organization, not the individual, so this resolves through
     * the user's organization; accounts without an organization fall back to
     * their own latest subscription row (admins and legacy rows).
     */
    protected function subscription(): Attribute
    {
        return Attribute::get(function (): ?Subscription {
            return $this->organization?->subscription
                ?? $this->subscriptions()->latest('id')->first();
        });
    }

    /**
     * All billing subscriptions created by this user as the purchaser.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The current plan via the organization's active subscription.
     */
    public function plan(): ?Plan
    {
        return $this->subscription?->plan;
    }

    /**
     * The usage counter for the current calendar month, created on demand.
     *
     * This is intentionally not the subscription anniversary: every counter in
     * the product keys off `UsageCounter::currentPeriodKey()`, so plan changes
     * mid-month inherit what was already spent rather than resetting it.
     */
    public function usageCounterForCurrentPeriod(): UsageCounter
    {
        return $this->usageCounters()->firstOrCreate(
            ['period_key' => UsageCounter::currentPeriodKey()],
            ['messages_used' => 0, 'messages_overage' => 0, 'documents_uploaded' => 0],
        );
    }

    /**
     * The monthly usage counters for this user.
     */
    public function usageCounters(): HasMany
    {
        return $this->hasMany(UsageCounter::class);
    }

    /**
     * The add-on integrations this user authorized.
     */
    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    /**
     * The user's connection for a provider, resolving a firm-wide connection
     * made by an admin when the organization runs in firm-wide mode.
     */
    public function integrationFor(IntegrationProvider $provider): ?Integration
    {
        $own = $this->integrations
            ->firstWhere('provider', $provider);

        if ($own !== null) {
            return $own;
        }

        if ($this->organization === null || ! $this->organization->usesFirmWideIntegrations()) {
            return null;
        }

        return $this->organization->integrations
            ->firstWhere('provider', $provider);
    }
}
