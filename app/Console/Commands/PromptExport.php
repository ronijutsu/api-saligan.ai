<?php

namespace App\Console\Commands;

use App\Services\Chat\ConversationPromptAssembler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Write the composed chat system prompt to a file.
 *
 * ai-provider now owns the prompt text (handoff §13, decision b1) and the port
 * was taken from the PHP source. This exists so the two can be diffed instead
 * of trusted: export the Laravel prompt, compose the provider's, and compare.
 * A switch of precedence is only safe once that diff is understood.
 */
class PromptExport extends Command
{
    protected $signature = 'prompt:export
        {--path= : Where to write; defaults to storage/app/static_instructions.txt}
        {--blocks-only : Omit the persona, so only the ported sections are compared}';

    protected $description = 'Export the composed chat system prompt for diffing against ai-provider';

    public function handle(ConversationPromptAssembler $prompts): int
    {
        $prompt = $prompts->staticInstructionsForPython();
        $persona = trim((string) $prompts->activeSystemPrompt()->content);

        if ($this->option('blocks-only') && $persona !== '' && str_starts_with($prompt, $persona)) {
            $prompt = trim(substr($prompt, strlen($persona)));
        }

        $path = (string) ($this->option('path') ?: storage_path('app/static_instructions.txt'));

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $prompt);

        $this->info(sprintf('Wrote %s chars to %s', number_format(strlen($prompt)), $path));

        return self::SUCCESS;
    }
}
