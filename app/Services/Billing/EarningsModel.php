<?php

namespace App\Services\Billing;

use InvalidArgumentException;

/**
 * Cost model for per-seat monthly earnings based on current plan pricing
 * (PHP, centavos), per-plan message caps, and per-message token costs.
 *
 * All monetary outputs are in Philippine pesos. Token assumptions are derived
 * from the actual system prompts and retrieval/history sizes used by the chat
 * feature. Chat providers invoice in USD, so the configured exchange rate
 * converts those to pesos.
 */
final class EarningsModel
{
    /**
     * Measured against `claude-sonnet-5` with Anthropic's count_tokens endpoint,
     * on the chat prompt: 22,934 tokens of system prompt, 13,340
     * of retrieved context (10 chunks at the configured legal/document caps),
     * and ~1,500 of history plus the question.
     *
     * Retrieval is counted at its cap, so this is the upper bound of a message
     * that retrieves well; a message that retrieves nothing costs far less.
     */
    public const INPUT_TOKENS_PER_MESSAGE = 37_774;

    /**
     * Unmeasured — nothing records per-message output tokens yet. Output bills
     * at five times input, so this is the assumption most worth replacing with
     * real data.
     */
    public const OUTPUT_TOKENS_PER_MESSAGE = 1_000;

    /**
     * The cacheable half of the system prompt — the static instructions
     * ai-provider marks with a `cache_control` breakpoint. Measured at
     * 21,886 tokens; the remaining ~1,048 tokens of system prompt vary per turn
     * and are billed at the uncached rate.
     */
    public const SYSTEM_PROMPT_TOKENS = 21_886;

    /**
     * Anthropic bills a cache write at 2x the input rate for the one-hour TTL
     * the product configures, and a read at 0.1x. A miss is therefore not
     * free: a quiet deployment whose requests fall outside the TTL pays more
     * than it would with caching off, which is why the hit rate is a
     * parameter rather than an assumption.
     */
    public const CACHE_WRITE_MULTIPLIER = 2.0;

    public const EMBEDDING_TOKENS_PER_DOCUMENT = 25_000;

    public const PAYMONGO_PERCENT = 0.035;

    public const PAYMONGO_FIXED_PESOS = 15.0;

    public const WEB_SEARCH_USD_PER_QUERY = 0.014;

    /**
     * Assumed monthly document uploads for plans without a document cap.
     */
    public const ASSUMED_UNLIMITED_DOCUMENTS = 500;

    /**
     * Chat provider prices in USD per 1M tokens (uncached input / output).
     *
     * @var array<string, array{label: string, input: float, output: float, cached_input: float}>
     */
    private const PROVIDERS = [
        // Anthropic is the configured chat provider (AI_CHAT_PROVIDER). Cached
        // input is the 0.1x cache-read rate. Sonnet 5's $2/$10 was originally
        // introductory pricing due to lapse on 2026-08-31; Anthropic has since
        // made it the standing rate, so there is no step-up to plan around.
        'claude-sonnet-5' => ['label' => 'Claude Sonnet 5', 'input' => 2.00, 'output' => 10.00, 'cached_input' => 0.20],
        'claude-haiku-4-5' => ['label' => 'Claude Haiku 4.5', 'input' => 1.00, 'output' => 5.00, 'cached_input' => 0.10],
        'claude-opus-5' => ['label' => 'Claude Opus 5', 'input' => 5.00, 'output' => 25.00, 'cached_input' => 0.50],
        // The standing Gemini Flash rate. Google is running an introductory
        // $0.75/$3.75 window (reported through 2026-12-31); the ladder is
        // costed at the rate that survives it, with the intro row below for
        // what-if runs.
        'gemini-flash' => ['label' => 'Gemini Flash', 'input' => 1.50, 'output' => 7.50, 'cached_input' => 0.15],
        'gemini-flash-intro' => ['label' => 'Gemini Flash (intro)', 'input' => 0.75, 'output' => 3.75, 'cached_input' => 0.075],
        'gpt-4o-mini' => ['label' => 'gpt-4o-mini', 'input' => 0.15, 'output' => 0.60, 'cached_input' => 0.075],
        'gpt-4o' => ['label' => 'gpt-4o', 'input' => 2.50, 'output' => 10.00, 'cached_input' => 1.25],
    ];

    public const EMBEDDING_PROVIDER = 'gemini-embedding-2';

    private const EMBEDDING_USD_PER_1M_TOKENS = 0.15;

    /**
     * Default plan definitions (price in centavos, message cap, per-message
     * overage price in centavos) used when the database is empty. Mirrors
     * database/seeders/PlansSeeder.php.
     *
     * @return array<int, array{slug: string, name: string, price: int, messages: int, documents: ?int, overage: ?int}>
     */
    public static function defaultPlans(): array
    {
        return [
            ['slug' => 'standard', 'name' => 'Standard', 'price' => 150_000, 'messages' => 250, 'documents' => 50, 'overage' => null],
            ['slug' => 'pro', 'name' => 'Pro', 'price' => 350_000, 'messages' => 300, 'documents' => 200, 'overage' => null],
            // Per seat, and the price is for three of them. Costed here as one
            // seat against the whole base price, which flatters Firm — use
            // `costing:seats` for the figure that divides it properly.
            ['slug' => 'firm', 'name' => 'Firm', 'price' => 1_100_000, 'messages' => 300, 'documents' => null, 'overage' => null],
        ];
    }

    /**
     * Per-message chat cost for a provider, in pesos.
     */
    public static function perMessageCostPesos(
        string $provider,
        float $exchangeRate,
        bool $cached = false,
        float $cacheHitRate = 1.0,
    ): float {
        return self::perMessageCostUsd($provider, $cached, $cacheHitRate) * $exchangeRate;
    }

    /**
     * Estimated embedding cost for a single uploaded document, in pesos.
     *
     * @param  float  $tokensPerDocument  Average tokens embedded per document.
     */
    public static function embeddingCostPerDocumentPesos(
        float $exchangeRate,
        float $tokensPerDocument = self::EMBEDDING_TOKENS_PER_DOCUMENT,
    ): float {
        return self::EMBEDDING_USD_PER_1M_TOKENS * $tokensPerDocument / 1_000_000 * $exchangeRate;
    }

    /**
     * Web search grounding cost per query, in pesos.
     */
    public static function webSearchCostPerQueryPesos(float $exchangeRate): float
    {
        return self::WEB_SEARCH_USD_PER_QUERY * $exchangeRate;
    }

    /**
     * PayMongo processing fee for a monthly charge, in pesos.
     */
    public static function paymongoFee(int $priceCents): float
    {
        return self::processingFee($priceCents, self::PAYMONGO_PERCENT, self::PAYMONGO_FIXED_PESOS);
    }

    /**
     * Processing fee for a monthly charge on any percentage-plus-fixed
     * schedule, in pesos.
     */
    public static function processingFee(int $priceCents, float $percent, float $fixedPesos): float
    {
        return $priceCents / 100 * $percent + $fixedPesos;
    }

    /**
     * Processing fee for a monthly charge on the active gateway's schedule, in
     * pesos.
     *
     * The model used to hardcode the PayMongo schedule while the checkout
     * could be PayPal or LemonSqueezy, so every margin it printed described a
     * gateway the money did not travel through. The schedule now comes from
     * `config/billing.php` and defaults to the PayMongo figures — set
     * BILLING_FEE_PERCENT / BILLING_FEE_FIXED_PESOS for the gateway in use.
     */
    public static function gatewayFee(int $priceCents): float
    {
        return self::processingFee(
            $priceCents,
            (float) config('billing.fee_percent', self::PAYMONGO_PERCENT),
            (float) config('billing.fee_fixed_pesos', self::PAYMONGO_FIXED_PESOS),
        );
    }

    /**
     * Message count at which revenue covers AI, search, PayMongo, and
     * embedding costs.
     *
     * @param  array<string, float>  $providerMix
     * @param  int  $assumedDocuments  Monthly documents when the plan has no cap.
     */
    public static function breakEvenMessages(
        int $priceCents,
        ?int $documentCap,
        array $providerMix,
        float $exchangeRate,
        bool $cached = false,
        float $webSearchRate = 0.20,
        int $assumedDocuments = self::ASSUMED_UNLIMITED_DOCUMENTS,
        float $cacheHitRate = 1.0,
    ): int {
        $revenuePesos = $priceCents / 100;
        $documentCount = $documentCap ?? $assumedDocuments;

        $fixedCosts = self::gatewayFee($priceCents)
            + self::embeddingCostPerDocumentPesos($exchangeRate) * $documentCount;

        $perMessage = self::blendedMessageCostPesos($providerMix, $exchangeRate, $cached, $cacheHitRate)
            + $webSearchRate * self::webSearchCostPerQueryPesos($exchangeRate);

        $total = $revenuePesos - $fixedCosts;
        if ($perMessage <= 0 || $total <= 0) {
            return 0;
        }

        return (int) ceil($total / $perMessage);
    }

    /**
     * Full per-plan monthly earnings breakdown, in pesos.
     *
     * @param  array<string, float>  $providerMix  e.g. ['gemini-flash' => 0.7, 'gpt-4o-mini' => 0.3]
     * @param  int  $assumedDocuments  Monthly documents when the plan has no cap.
     * @return array{
     *     price_cents: int,
     *     price_pesos: float,
     *     messages: int,
     *     message_cost_pesos: float,
     *     ai_cogs_pesos: float,
     *     embeddings_pesos: float,
     *     web_search_pesos: float,
     *     paymongo_pesos: float,
     *     net_pesos: float,
     *     margin: float,
     * }
     */
    public static function earnings(
        int $priceCents,
        int $messageCap,
        ?int $documentCap,
        array $providerMix,
        float $exchangeRate,
        bool $cached = false,
        float $webSearchRate = 0.20,
        int $assumedDocuments = self::ASSUMED_UNLIMITED_DOCUMENTS,
        float $cacheHitRate = 1.0,
    ): array {
        self::assertProviderMix($providerMix);

        $pricePesos = $priceCents / 100;
        $documentCount = $documentCap ?? $assumedDocuments;

        $messageCost = self::blendedMessageCostPesos($providerMix, $exchangeRate, $cached, $cacheHitRate);

        $aiCogs = $messageCost * $messageCap;
        $embeddings = self::embeddingCostPerDocumentPesos($exchangeRate) * $documentCount;
        $webSearch = self::webSearchCostPerQueryPesos($exchangeRate) * ($webSearchRate * $messageCap);
        $paymongo = self::gatewayFee($priceCents);

        $net = $pricePesos - $aiCogs - $embeddings - $webSearch - $paymongo;

        return [
            'price_cents' => $priceCents,
            'price_pesos' => $pricePesos,
            'messages' => $messageCap,
            'message_cost_pesos' => round($messageCost, 2),
            'ai_cogs_pesos' => round($aiCogs, 2),
            'embeddings_pesos' => round($embeddings, 2),
            'web_search_pesos' => round($webSearch, 2),
            'paymongo_pesos' => round($paymongo, 2),
            'net_pesos' => round($net, 2),
            'margin' => round($pricePesos > 0 ? $net / $pricePesos : 0, 4),
        ];
    }

    /**
     * Per-seat earnings for a multi-seat plan, in pesos.
     *
     * Seats are the unit firms budget in; each seat carries its own message
     * allowance (see PlanLimits::consumeMessage), so the allowance here is the
     * sum of the per-seat allowances, not a shared pool any seat can draw
     * from. Because most seats consume well under their share, the realised
     * cost sits below the worst case rather than at it. `$utilisation` is that
     * share — 1.0 prices the plan as if every seat drains its full allowance.
     *
     * `$costPerMessagePesos` overrides the modelled token cost with an observed
     * one, for when real spend is known and the token assumptions are not.
     *
     * @param  array<string, float>  $providerMix
     * @return array{
     *     price_pesos: float,
     *     seats: int,
     *     allowance: int,
     *     messages_consumed: int,
     *     message_cost_pesos: float,
     *     ai_cogs_pesos: float,
     *     embeddings_pesos: float,
     *     web_search_pesos: float,
     *     paymongo_pesos: float,
     *     net_pesos: float,
     *     net_per_seat_pesos: float,
     *     margin: float,
     * }
     */
    public static function seatEarnings(
        int $pricePerSeatCents,
        int $seats,
        int $pooledMessagesPerSeat,
        array $providerMix,
        float $exchangeRate,
        float $utilisation = 1.0,
        bool $cached = true,
        float $cacheHitRate = 1.0,
        float $webSearchRate = 0.20,
        int $documentsPerSeat = 10,
        ?float $costPerMessagePesos = null,
    ): array {
        self::assertProviderMix($providerMix);

        $revenue = $pricePerSeatCents * $seats / 100;
        $allowance = $pooledMessagesPerSeat * $seats;
        $consumed = (int) round($allowance * max(0.0, $utilisation));

        $messageCost = $costPerMessagePesos
            ?? self::blendedMessageCostPesos($providerMix, $exchangeRate, $cached, $cacheHitRate);

        $aiCogs = $messageCost * $consumed;
        $embeddings = self::embeddingCostPerDocumentPesos($exchangeRate) * $documentsPerSeat * $seats;
        $webSearch = self::webSearchCostPerQueryPesos($exchangeRate) * ($webSearchRate * $consumed);
        // One subscription per organization, not per seat.
        $paymongo = self::gatewayFee($pricePerSeatCents * $seats);

        $net = $revenue - $aiCogs - $embeddings - $webSearch - $paymongo;

        return [
            'price_pesos' => $revenue,
            'seats' => $seats,
            'allowance' => $allowance,
            'messages_consumed' => $consumed,
            'message_cost_pesos' => round($messageCost, 2),
            'ai_cogs_pesos' => round($aiCogs, 2),
            'embeddings_pesos' => round($embeddings, 2),
            'web_search_pesos' => round($webSearch, 2),
            'paymongo_pesos' => round($paymongo, 2),
            'net_pesos' => round($net, 2),
            'net_per_seat_pesos' => round($seats > 0 ? $net / $seats : 0, 2),
            'margin' => round($revenue > 0 ? $net / $revenue : 0, 4),
        ];
    }

    /**
     * Provider metadata for display.
     *
     * @return array{label: string, input: float, output: float, cached_input: float}
     */
    public static function provider(string $provider): array
    {
        if (! isset(self::PROVIDERS[$provider])) {
            throw new InvalidArgumentException("Unknown provider [$provider].");
        }

        return self::PROVIDERS[$provider];
    }

    /**
     * Blended per-message chat cost across a provider mix, in pesos.
     *
     * @param  array<string, float>  $providerMix
     */
    private static function blendedMessageCostPesos(
        array $providerMix,
        float $exchangeRate,
        bool $cached,
        float $cacheHitRate = 1.0,
    ): float {
        $cost = 0.0;

        foreach ($providerMix as $provider => $share) {
            $cost += $share * self::perMessageCostUsd($provider, $cached, $cacheHitRate);
        }

        return $cost * $exchangeRate;
    }

    /**
     * Per-message chat cost for a provider, in USD.
     */
    private static function perMessageCostUsd(
        string $provider,
        bool $cached = false,
        float $cacheHitRate = 1.0,
    ): float {
        $p = self::provider($provider);

        $inputPrice = $cached
            ? self::cachedInputCost($provider, $cacheHitRate)
            : $p['input'] * self::INPUT_TOKENS_PER_MESSAGE / 1_000_000;

        $outputCost = $p['output'] * self::OUTPUT_TOKENS_PER_MESSAGE / 1_000_000;

        return $inputPrice + $outputCost;
    }

    /**
     * Input cost of the system prompt per message when caching is on, in USD.
     *
     * A miss is not free: it writes the block at 2x the input rate under the
     * configured one-hour TTL, so a low-traffic deployment whose requests fall
     * outside the TTL pays more than it would with caching off. The blend
     * makes that visible rather than assuming every request lands on a warm
     * cache.
     */
    private static function cachedInputCost(string $provider, float $cacheHitRate): float
    {
        $p = self::provider($provider);

        $hitRate = max(0.0, min(1.0, $cacheHitRate));

        $staticRate = $hitRate * $p['cached_input']
            + (1 - $hitRate) * $p['input'] * self::CACHE_WRITE_MULTIPLIER;

        return $staticRate * self::SYSTEM_PROMPT_TOKENS / 1_000_000
            + $p['input'] * (self::INPUT_TOKENS_PER_MESSAGE - self::SYSTEM_PROMPT_TOKENS) / 1_000_000;
    }

    /**
     * @param  array<string, float>  $providerMix
     */
    private static function assertProviderMix(array $providerMix): void
    {
        foreach ($providerMix as $provider => $share) {
            self::provider($provider);

            if ($share < 0) {
                throw new InvalidArgumentException("Provider [$provider] share cannot be negative.");
            }
        }

        if (abs(array_sum($providerMix) - 1.0) > 0.0001) {
            throw new InvalidArgumentException('Provider mix shares must sum to 1.');
        }
    }
}
