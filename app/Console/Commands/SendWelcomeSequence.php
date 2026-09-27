<?php

namespace App\Console\Commands;

use App\Services\Lifecycle\WelcomeSequence;
use Illuminate\Console\Command;

class SendWelcomeSequence extends Command
{
    protected $signature = 'emails:welcome-sequence';

    protected $description = 'Send the due step of the welcome email sequence to trial users';

    public function handle(WelcomeSequence $sequence): int
    {
        if (! config('saligan.welcome_sequence.enabled')) {
            $this->info('Welcome sequence is disabled (WELCOME_SEQUENCE_ENABLED).');

            return self::SUCCESS;
        }

        $sent = $sequence->sweep();

        $this->info("Welcome emails sent: {$sent}");

        return self::SUCCESS;
    }
}
