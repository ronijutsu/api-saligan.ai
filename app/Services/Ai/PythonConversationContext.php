<?php

namespace App\Services\Ai;

use App\Enums\ChatProvider;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Template;
use App\Models\User;
use App\Services\Chat\ConversationPromptAssembler;
use App\Services\MatterMemory\MatterMemoryService;
use App\Support\CaseContextBlock;
use App\Support\PlanFeatures;
use App\Support\UserProfile;
use Illuminate\Support\Facades\Log;

class PythonConversationContext
{
    private const CONTEXT_CONTRACT_VERSION = 1;

    public function __construct(
        private readonly CaseContextBlock $caseContext,
        private readonly MatterMemoryService $memory,
        private readonly ConversationPromptAssembler $prompts,
    ) {}

    /** @return array<string, mixed> */
    public function for(Conversation $conversation, ?string $currentMessage = null): array
    {
        $conversation->loadMissing(['user.organization.subscription.plan', 'user.subscriptions.plan', 'case.defaultTemplate']);

        $user = $conversation->user;
        $case = $conversation->case;
        $capabilities = $this->effectiveCapabilities($user);
        $deepResearch = in_array(PlanFeatures::DEEP_RESEARCH, $capabilities, true);
        $webSearchEnabled = in_array(PlanFeatures::WEB_SEARCH, $capabilities, true)
            && (bool) config('saligan.web_search.enabled', false);
        $prompt = $this->prompts->activeSystemPrompt();
        $template = $this->prompts->resolveTemplate($conversation, $currentMessage ?? '');
        [$provider, $model] = $this->providerAndModel($conversation);

        return [
            'context_contract_version' => self::CONTEXT_CONTRACT_VERSION,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'case_id' => $case?->id,
            'capabilities' => $capabilities,
            'deep_research' => $deepResearch,
            'web_search_enabled' => $webSearchEnabled,
            'web_search_max_calls' => $webSearchEnabled ? $this->webSearchBudget($deepResearch) : 0,
            'provider' => $provider,
            'model' => $model,
            'plan_tier' => $user->plan()?->slug,
            // The provider composes the prompt itself: it reads the persona
            // from the database and owns the rules (handoff §13, b1). Shipping
            // the rendered ~79k-character prompt on every turn was ~20k tokens
            // of payload the provider had stopped reading. The persona stays as
            // the fallback for a deployment whose provider cannot reach the
            // database.
            'system_prompt' => [
                'id' => (string) $prompt->id,
                'version' => (int) $prompt->version,
                'content' => (string) $prompt->content,
            ],
            'persistence' => [
                'conversation_id' => (string) $conversation->id,
                'user_id' => $user->id,
                'case_id' => $case?->id,
            ],
            'user_profile' => UserProfile::blockFor($user) ?? '',
            'case_context' => $case !== null ? $this->caseContext->for($case) : '',
            'matter_memory' => $case !== null ? $this->memory->getMemoryBlock($case) : '',
            'template' => $template !== null ? $this->template($template) : '',
            'resolved_template' => $template !== null ? $this->resolvedTemplate($template) : null,
            'template_mode' => $template?->isVerbatimTemplate() ? 'verbatim' : ($template !== null ? 'structured' : null),
            'recent_intake_values' => (object) $this->prompts->recentIntakeValues($conversation),
            'messages' => $conversation->messages()
                ->whereIn('role', [MessageRole::User->value, MessageRole::Assistant->value])
                ->latest()
                ->limit(20)
                ->get()
                ->reverse()
                ->map(fn ($message): array => [
                    'role' => $message->role->value,
                    'content' => $message->content,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The plan features effective for this user, in the catalogue's stable
     * order. Service promises are deliberately excluded by the catalogue.
     *
     * @return list<string>
     */
    protected function effectiveCapabilities(User $user): array
    {
        return array_values(array_filter(
            PlanFeatures::capabilities(),
            fn (string $feature): bool => PlanFeatures::has($user, $feature),
        ));
    }

    protected function webSearchBudget(bool $deepResearch): int
    {
        return max(0, (int) config(
            $deepResearch ? 'saligan.web_search.max_searches' : 'saligan.web_search.base_max_searches',
            0,
        ));
    }

    /** @return array{0: string, 1: string} */
    protected function providerAndModel(Conversation $conversation): array
    {
        $provider = ChatProvider::fromConfig();

        return match ($provider) {
            ChatProvider::Anthropic => filled(config('ai.providers.anthropic.key')) ? [
                'anthropic',
                PlanFeatures::has($conversation->user, PlanFeatures::FRONTIER_MODEL)
                    ? (string) config('saligan.chat.anthropic_model')
                    : (string) config('saligan.chat.anthropic_base_model'),
            ] : $this->ollama(),
            ChatProvider::Gemini => filled(config('ai.providers.gemini.key'))
                ? ['gemini', (string) config('saligan.chat.gemini_model')]
                : $this->ollama(),
            // OpenRouter serves through the OpenAI-compatible client, so it
            // forwards rather than mapping: what the operator configured is
            // what answers.
            ChatProvider::Openrouter => filled(config('ai.providers.openrouter.key'))
                ? ['openrouter', (string) config('saligan.chat.openrouter_model')]
                : $this->ollama(),
            // Meta's endpoint is OpenAI-compatible and the Python adapter has
            // a first-class Meta branch. Forward it when configured so the
            // selected provider/model and billing metadata stay truthful.
            ChatProvider::Meta => filled(config('ai.providers.meta.key'))
                ? ['meta', (string) config('saligan.chat.meta_model')]
                : $this->ollama(),
            // OpenAI is still not a provider supported by the Python adapter;
            // retain the explicit hosted fallback until that client lands.
            ChatProvider::OpenAI => $this->hostedFallback($provider),
            default => $this->ollama(),
        };
    }

    /** @return array{0: string, 1: string} */
    protected function hostedFallback(ChatProvider $provider): array
    {
        Log::warning('Python AI provider has no client for the configured chat provider; serving Gemini instead.', [
            'configured_provider' => $provider->value,
        ]);

        if (filled(config('ai.providers.gemini.key'))) {
            return ['gemini', (string) config('saligan.chat.gemini_model')];
        }

        return $this->ollama();
    }

    /** @return array{0: string, 1: string} */
    protected function ollama(): array
    {
        return ['ollama', (string) config('saligan.chat.ollama_model')];
    }

    protected function template(Template $template): string
    {
        return collect([
            "Template: {$template->name}",
            "Category: {$template->category}",
            filled($template->legal_subtype) ? "Legal sub-type: {$template->legal_subtype}" : null,
            filled($template->content) ? "Content:\n{$template->content}" : null,
            filled($template->placeholder_fields)
                ? 'Placeholder fields: '.json_encode($template->placeholder_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null,
        ])->filter()->implode("\n");
    }

    /** @return array<string, mixed> */
    protected function resolvedTemplate(Template $template): array
    {
        return [
            'id' => (string) $template->id,
            'mode' => $template->isVerbatimTemplate() ? 'verbatim' : 'structured',
            'name' => $template->name,
            'category' => $template->category,
            'legal_subtype' => $template->legal_subtype,
            'content' => $template->content,
            'structure' => $template->structure ?? [],
            'placeholder_fields' => $template->placeholder_fields ?? [],
        ];
    }
}
