<?php

namespace App\Services\Chat;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\SystemPrompt;
use App\Models\Template;
use App\Support\DraftingIntent;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The persona and template resolution behind the chat context Laravel sends
 * to ai-provider (PythonConversationContext). ai-provider composes the prompt
 * rules itself.
 */
class ConversationPromptAssembler
{
    /**
     * The active persona, sent to ai-provider with its identity.
     */
    public function activeSystemPrompt(): SystemPrompt
    {
        return SystemPrompt::activeFor('batayan')
            ?? SystemPrompt::activeFor('saligan')
            ?? throw new \RuntimeException('No active Batayan system prompt is configured.');
    }

    /**
     * Resolve the template to use for drafting: an explicit directive from the
     * template picker, a template referenced by name in the question, then the
     * case's default template.
     */
    public function resolveTemplate(Conversation $conversation, string $question): ?Template
    {
        [$directive, $prompt] = DraftingIntent::extractTemplateDirective($question);

        $query = Template::query()
            ->visibleTo($conversation->user)
            ->closestTo($conversation->user);

        if ($directive !== null) {
            return Str::isUuid($directive)
                ? $query->where('id', $directive)->first()
                : $query->where('legal_subtype', $directive)->first();
        }

        $named = $this->matchTemplateByName($query->get(), $prompt);

        if ($named !== null) {
            return $named;
        }

        if ($conversation->case?->default_template_id !== null) {
            return Template::query()
                ->visibleTo($conversation->user)
                ->where('id', $conversation->case->default_template_id)
                ->first();
        }

        return null;
    }

    /**
     * Match a template the user referred to by name in natural language, e.g.
     * 'using the "Barangay Complaint (Sumbong)" template'. Names are matched
     * case-insensitively with punctuation stripped so quotes and parentheses
     * around the name do not prevent a match.
     *
     * Candidates are checked longest-first so the most specific name wins when
     * several templates share a common substring (e.g. "Deed of Sale" before
     * "Deed"), instead of whichever row the collection happened to return
     * first.
     *
     * @param  Collection<int, Template>  $templates
     */
    public function matchTemplateByName($templates, string $prompt): ?Template
    {
        $needle = $this->templateNameKey($prompt);

        if ($needle === '') {
            return null;
        }

        $candidates = [];

        foreach ($templates as $template) {
            foreach ([$template->name, $template->legal_subtype] as $candidate) {
                if (filled($candidate)) {
                    $candidates[] = [$template, (string) $candidate];
                }
            }
        }

        usort($candidates, fn (array $a, array $b): int => mb_strlen($b[1]) <=> mb_strlen($a[1]));

        foreach ($candidates as [$template, $candidate]) {
            if (str_contains($needle, $this->templateNameKey($candidate))) {
                return $template;
            }
        }

        return null;
    }

    /**
     * A searchable key for a template name or subtype: lowercased, with all
     * non-alphanumeric characters removed.
     */
    protected function templateNameKey(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $value));
    }

    /**
     * The most recently submitted intake values in the conversation, parsed
     * from the latest "[Intake Form Submission]" user message. Used to pre-fill
     * the intake form when the user drafts the same document again, so the
     * regeneration reuses their original answers instead of blank fields.
     *
     * @param  string|null  $excludeMessageId  A message to ignore, so a turn does not
     *                                         read the submission it just created.
     * @return array<string, string>
     */
    public function recentIntakeValues(Conversation $conversation, ?string $excludeMessageId = null): array
    {
        $query = $conversation->messages()
            ->where('role', MessageRole::User)
            ->latest('created_at');

        if ($excludeMessageId !== null) {
            $query->whereKeyNot($excludeMessageId);
        }

        $content = $query->value('content');

        if ($content === null || ! str_starts_with($content, '[Intake Form Submission]')) {
            return [];
        }

        $values = [];

        foreach (array_slice(explode("\n", $content), 1) as $line) {
            $parts = explode(': ', $line, 2);

            if (count($parts) === 2) {
                $values[trim($parts[0])] = trim($parts[1]);
            }
        }

        return DraftingIntent::canonicalizeIntakeValues($values);
    }
}
