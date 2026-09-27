<?php

namespace App\Jobs\Middleware;

use Illuminate\Support\Facades\Cache;

/**
 * Cap how many ingestion jobs one account may run at the same time.
 *
 * A single 25 MB document can occupy a document-processing worker for many
 * minutes, so an account that uploads a dozen at once can take the whole pool
 * and leave every other tenant queued behind it. This bounds that at the job
 * level: each user has N slots, and a job that finds them all taken goes back
 * on the queue rather than blocking a worker.
 *
 * Slots are cache locks with a TTL longer than the job timeout, so a worker
 * that is killed mid-document cannot strand a slot — the lock expires and the
 * account's capacity returns without operator intervention.
 */
class LimitUserIngestConcurrency
{
    public function __construct(private readonly int $userId) {}

    public function handle(object $job, callable $next): void
    {
        $limit = max(1, (int) config('saligan.documents.max_concurrent_ingests_per_user', 2));

        $slot = $this->acquire($limit);

        if ($slot === null) {
            // Everything this account is allowed to run is running. Try again
            // shortly; nothing is lost and no worker is held.
            $job->release(30);

            return;
        }

        try {
            $next($job);
        } finally {
            $slot->release();
        }
    }

    /**
     * Take the first free slot, or null when they are all in use.
     */
    protected function acquire(int $limit): ?object
    {
        for ($slot = 1; $slot <= $limit; $slot++) {
            $lock = Cache::lock($this->key($slot), self::SLOT_TTL_SECONDS);

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    protected function key(int $slot): string
    {
        return "document-ingest:user:{$this->userId}:slot:{$slot}";
    }

    /**
     * Longer than the job's own timeout so a slow document keeps its slot for
     * its whole run, and short enough that a killed worker's slot is reclaimed
     * without a human.
     */
    private const SLOT_TTL_SECONDS = 1800;
}
