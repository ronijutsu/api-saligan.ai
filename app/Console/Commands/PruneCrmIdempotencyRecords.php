<?php

namespace App\Console\Commands;

use App\Models\CrmIdempotencyRecord;
use Illuminate\Console\Command;

class PruneCrmIdempotencyRecords extends Command
{
    protected $signature = 'crm:prune-idempotency';

    protected $description = 'Delete expired CRM idempotency records';

    public function handle(): int
    {
        $count = CrmIdempotencyRecord::query()
            ->where('expires_at', '<=', now())
            ->delete();

        $this->info("Expired CRM idempotency records deleted: {$count}");

        return self::SUCCESS;
    }
}
