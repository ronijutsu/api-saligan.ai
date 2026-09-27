<?php

namespace App\Services\Documents;

use App\Exceptions\DocumentProcessingException;
use App\Models\DocumentChunk;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The persistent vector-index quota.
 *
 * The AI budget gates monthly spend, and it resets every month. Vectors do not:
 * an indexed chunk stays in `document_chunks` (and its HNSW entry in the index)
 * until the document is deleted. So a heavy month is capped in dollars while
 * its storage cost is paid forever, which is the gap this quota closes — a
 * hard ceiling on how many chunks one account may hold at once.
 *
 * Organization plans pool their AI budget across members, so this pools the
 * quota the same way: a Firm workspace shares one index allowance rather than
 * each seat carrying a private one.
 */
class DocumentIndexQuota
{
    /**
     * The number of indexed chunks the given user's account currently holds.
     */
    public function used(User $user): int
    {
        return (int) DocumentChunk::query()
            ->whereIn('user_id', $this->scopeUserIds($user))
            ->count();
    }

    public function limit(): int
    {
        return max(1, (int) config('saligan.documents.max_indexed_chunks_per_user', 50_000));
    }

    public function remaining(User $user): int
    {
        return max(0, $this->limit() - $this->used($user));
    }

    /**
     * Refuse an ingestion that would take the account past its index ceiling.
     * Called before any embedding is requested, so an over-quota upload costs
     * nothing and fails while the uploader is still watching.
     */
    public function assertCanIndex(User $user, int $newChunks): void
    {
        $limit = $this->limit();

        if ($newChunks <= 0) {
            return;
        }

        $used = $this->used($user);

        if ($used + $newChunks <= $limit) {
            return;
        }

        throw new DocumentProcessingException(sprintf(
            'Your document index is full: %s of %s passages are in use and this upload needs %s more. '
            .'Delete documents you no longer need, then upload again.',
            number_format($used),
            number_format($limit),
            number_format($newChunks),
        ));
    }

    /**
     * Whether the account has any room left, for a cheap pre-check at upload
     * time when the chunk count is not yet known.
     */
    public function hasRoom(User $user): bool
    {
        return $this->used($user) < $this->limit();
    }

    /**
     * The user ids whose chunks count against this account: the whole
     * organization for a member, otherwise just the user.
     *
     * @return array<int, int>
     */
    protected function scopeUserIds(User $user): array
    {
        if ($user->organization_id === null) {
            return [$user->id];
        }

        $ids = User::query()
            ->where('organization_id', $user->organization_id)
            ->pluck('id')
            ->all();

        return $ids === [] ? [$user->id] : array_map('intval', $ids);
    }

    /**
     * The index footprint in bytes, for reporting. Uses the measured
     * ~3.6 kB per row (vector + HNSW entry) as an estimate.
     */
    public function estimatedBytes(User $user): int
    {
        return $this->used($user) * 3_618;
    }

    /**
     * Whether the chunks table is reachable. Kept separate so a quota check
     * never becomes the reason an ingestion fails outright.
     */
    public function isAvailable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
