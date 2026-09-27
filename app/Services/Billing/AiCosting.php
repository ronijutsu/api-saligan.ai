<?php

namespace App\Services\Billing;

use App\Enums\ChatProvider;

/**
 * What metered AI work costs, in USD.
 *
 * One pinned rate table (`RATE_VERSION`) turns provider-reported token counts
 * into the ledger's dollars. Pinning matters: provider prices move, and a
 * cost row must forever mean what it meant on the day it settled, so rate
 * changes ship as a new version here rather than an edit to these numbers.
 * Local Ollama work bills nothing per token — its cost is the host, which
 * lives outside this meter.
 */
final class AiCosting
{
    public const RATE_VERSION = '2026-09-09-fx65';

    /**
     * USD per 1M tokens: input / output / cache-read. Cache writes bill at
     * twice input under the configured one-hour TTL.
     *
     * @var array<string, array{input: float, output: float, cached_input: float}>
     */
    private const RATES = [
        'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00, 'cached_input' => 0.20],
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00, 'cached_input' => 0.10],
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00, 'cached_input' => 0.50],
        'gemini-flash' => ['input' => 1.50, 'output' => 7.50, 'cached_input' => 0.15],
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00, 'cached_input' => 1.25],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60, 'cached_input' => 0.075],
        'muse-spark' => ['input' => 1.25, 'output' => 4.25, 'cached_input' => 0.15],
    ];

    public const GROUNDING_USD_PER_QUERY = 0.014;

    public const EMBEDDING_USD_PER_1M_TOKENS = 0.15;

    /**
     * Modeled flat add-ons for ingestion steps the providers do not count
     * back: vision OCR and the filing classifier. Deliberately small and
     * documented — they complete the ingestion ledger, and any sustained drift
     * shows up against invoices.
     */
    public const OCR_ADDON_USD = 0.02;

    /**
     * Vision OCR runs once per page, so it is charged per page. The flat
     * `OCR_ADDON_USD` above priced a 300-page scan the same as a one-page
     * photo, which is how a single upload could spend a month's allowance
     * while the ledger recorded two cents.
     */
    public const OCR_USD_PER_PAGE = 0.02;

    /**
     * Cost of OCR for a known page count.
     */
    public static function ocrCostUsd(int $pages): float
    {
        return max(0, $pages) * self::OCR_USD_PER_PAGE;
    }

    public const CLASSIFY_ADDON_USD = 0.006;

    public const CACHE_WRITE_MULTIPLIER = 2.0;

    /**
     * Resolve a provider/model pair to a rate row. Unknown cloud models fall
     * to the frontier rate rather than zero — understating a turn the meter
     * never saw coming is how margins die quietly. Returns null only for
     * local inference, which genuinely bills no tokens.
     *
     * @return array{input: float, output: float, cached_input: float}|null
     */
    public static function ratesFor(?string $provider, ?string $model): ?array
    {
        $provider = strtolower((string) $provider);
        $model = strtolower((string) $model);

        if ($provider === '' || $provider === 'ollama' || str_contains($model, 'qwen') || str_contains($model, 'ollama')) {
            return null;
        }

        if ($model !== '') {
            // Full model ids first — a short family needle like `claude`
            // would otherwise match haiku turns to the sonnet rate.
            foreach (self::RATES as $key => $rates) {
                if (str_contains($model, $key)) {
                    return $rates;
                }
            }

            if (str_contains($model, 'haiku')) {
                return self::RATES['claude-haiku-4-5'];
            }

            if (str_contains($model, 'opus')) {
                return self::RATES['claude-opus-5'];
            }

            if (str_contains($model, 'sonnet')) {
                return self::RATES['claude-sonnet-5'];
            }

            if (str_contains($model, 'flash')) {
                return self::RATES['gemini-flash'];
            }

            if (str_contains($model, 'gpt-4o-mini') || str_contains($model, '4o-mini')) {
                return self::RATES['gpt-4o-mini'];
            }

            if (str_contains($model, 'gpt-4o') || str_contains($model, '4o')) {
                return self::RATES['gpt-4o'];
            }

            if (str_contains($model, 'muse')) {
                return self::RATES['muse-spark'];
            }
        }

        return match (true) {
            str_contains($provider, 'anthropic') || $provider === ChatProvider::Anthropic->value => self::RATES['claude-sonnet-5'],
            str_contains($provider, 'gemini') || str_contains($provider, 'google') || $provider === ChatProvider::Gemini->value => self::RATES['gemini-flash'],
            str_contains($provider, 'openai') || $provider === ChatProvider::OpenAI->value => self::RATES['gpt-4o'],
            str_contains($provider, 'meta') || str_contains($provider, 'muse') || $provider === ChatProvider::Meta->value => self::RATES['muse-spark'],
            default => self::RATES['claude-sonnet-5'],
        };
    }

    /**
     * Cost one model call in USD from provider-reported token counts.
     */
    public static function callCostUsd(
        ?string $provider,
        ?string $model,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $cacheReadTokens = 0,
        int $cacheWriteTokens = 0,
    ): float {
        $rates = self::ratesFor($provider, $model);

        if ($rates === null) {
            return 0.0;
        }

        // Cache reads arrive inside input totals on some providers; never let
        // the same token bill twice.
        $uncachedInput = max(0, $inputTokens - $cacheReadTokens - $cacheWriteTokens);

        return ($uncachedInput * $rates['input']
            + $cacheReadTokens * $rates['cached_input']
            + $cacheWriteTokens * $rates['input'] * self::CACHE_WRITE_MULTIPLIER
            + $outputTokens * $rates['output']) / 1_000_000;
    }

    /**
     * Cost a chat turn's persisted usage summary: the answering call plus
     * every helper call the turn fanned out to, plus billed search queries.
     *
     * @param  array<string, mixed>  $usage  The `metadata.usage` shape both
     *                                       engines persist.
     */
    public static function turnCostUsd(array $usage, ?string $provider = null, ?string $model = null): float
    {
        $provider ??= $usage['provider'] ?? null;
        $model ??= $usage['model'] ?? null;

        $total = self::callCostUsd(
            is_string($provider) ? $provider : null,
            is_string($model) ? $model : null,
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            (int) ($usage['cache_read_tokens'] ?? 0),
            (int) ($usage['cache_write_tokens'] ?? 0),
        );

        foreach ($usage['search_calls'] ?? [] as $call) {
            $callUsage = is_array($call['usage'] ?? null) ? $call['usage'] : [];
            // Research always runs on Gemini Flash regardless of the
            // answering provider.
            $total += self::callCostUsd(
                'gemini',
                'gemini-flash',
                (int) ($callUsage['input_tokens'] ?? 0),
                (int) ($callUsage['output_tokens'] ?? 0),
                (int) ($callUsage['cache_read_tokens'] ?? 0),
                (int) ($callUsage['cache_write_tokens'] ?? 0),
            );
            $total += self::GROUNDING_USD_PER_QUERY;
        }

        foreach ($usage['letter_calls'] ?? [] as $call) {
            $callUsage = is_array($call['usage'] ?? null) ? $call['usage'] : [];
            $total += self::callCostUsd(
                is_string($callUsage['provider'] ?? null) ? $callUsage['provider'] : $provider,
                is_string($callUsage['model'] ?? null) ? $callUsage['model'] : $model,
                (int) ($callUsage['input_tokens'] ?? 0),
                (int) ($callUsage['output_tokens'] ?? 0),
                (int) ($callUsage['cache_read_tokens'] ?? 0),
                (int) ($callUsage['cache_write_tokens'] ?? 0),
            );
        }

        // A lone letter-draft turn carries its cost on `letter_usage`.
        if (is_array($usage['letter_usage'] ?? null)) {
            $letterUsage = $usage['letter_usage'];
            $total += self::callCostUsd(
                is_string($letterUsage['provider'] ?? null) ? $letterUsage['provider'] : $provider,
                is_string($letterUsage['model'] ?? null) ? $letterUsage['model'] : $model,
                (int) ($letterUsage['input_tokens'] ?? 0),
                (int) ($letterUsage['output_tokens'] ?? 0),
                (int) ($letterUsage['cache_read_tokens'] ?? 0),
                (int) ($letterUsage['cache_write_tokens'] ?? 0),
            );
        }

        return $total;
    }

    /**
     * Embedding cost in USD for a known token count.
     */
    public static function embeddingCostUsd(int $tokens): float
    {
        return max(0, $tokens) * self::EMBEDDING_USD_PER_1M_TOKENS / 1_000_000;
    }

    /**
     * Rough token count for text the provider never counted — chunked
     * extracts bound for embeddings. Four characters per token is the
     * industry's standard napkin, and a napkin is all this needs to be: it
     * prices ingestion estimates, never chat turns.
     */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text, '8bit') / 4);
    }

    /**
     * The pre-flight hold for an operation, in USD. Always replaced by the
     * measured cost at settle time.
     */
    public static function estimateFor(string $operation): float
    {
        $estimates = config('billing.usage_estimates_usd', []);

        return (float) ($estimates[$operation] ?? $estimates['chat'] ?? 0.08);
    }

    /**
     * Display conversion. The rate is a budgeting cushion, not a settlement
     * promise — see `config/billing.php`.
     */
    public static function toPesos(float $usd): float
    {
        return $usd * (float) config('billing.fx_usd_php', 65.0);
    }
}
