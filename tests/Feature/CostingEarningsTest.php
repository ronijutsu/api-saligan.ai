<?php

use App\Services\Billing\EarningsModel;

// Token counts come from the model's own constants rather than repeated
// literals: duplicating them is how the expectations silently drifted from the
// measured prompt size the last time it changed.
it('computes per-message cost in pesos for each provider', function (string $provider, float $input, float $output) {
    $expected = (EarningsModel::INPUT_TOKENS_PER_MESSAGE / 1_000_000 * $input
        + EarningsModel::OUTPUT_TOKENS_PER_MESSAGE / 1_000_000 * $output) * 57.0;

    // Delta rather than identity: the model and the expectation reach the same
    // figure by a different order of operations, which differs in the last bit.
    expect(EarningsModel::perMessageCostPesos($provider, 57.0))->toEqualWithDelta($expected, 1e-9);
})->with([
    ['claude-sonnet-5', 2.00, 10.00],
    ['claude-haiku-4-5', 1.00, 5.00],
    ['gemini-flash', 1.50, 7.50],
    ['gpt-4o-mini', 0.15, 0.60],
    ['gpt-4o', 2.50, 10.00],
]);

it('cheapens the message when the cache is warm', function () {
    $uncached = EarningsModel::perMessageCostPesos('claude-sonnet-5', 57.0);
    $cached = EarningsModel::perMessageCostPesos('claude-sonnet-5', 57.0, cached: true);

    expect($cached)->toBeLessThan($uncached);
});

it('costs more than no caching at all when every request misses the cache', function () {
    // The one-hour TTL means a quiet deployment writes the block far more
    // often than it reads it, and a write bills at 2x the input rate.
    $uncached = EarningsModel::perMessageCostPesos('claude-sonnet-5', 57.0);
    $allMisses = EarningsModel::perMessageCostPesos('claude-sonnet-5', 57.0, cached: true, cacheHitRate: 0.0);

    expect($allMisses)->toBeGreaterThan($uncached);
});

it('prices a partial cache hit rate between the hit and miss extremes', function () {
    $hit = EarningsModel::perMessageCostPesos('claude-sonnet-5', 57.0, cached: true, cacheHitRate: 1.0);
    $miss = EarningsModel::perMessageCostPesos('claude-sonnet-5', 57.0, cached: true, cacheHitRate: 0.0);
    $half = EarningsModel::perMessageCostPesos('claude-sonnet-5', 57.0, cached: true, cacheHitRate: 0.5);

    expect($half)->toBeGreaterThan($hit)->toBeLessThan($miss);
});

it('throws for an unknown provider', function () {
    EarningsModel::perMessageCostPesos('nope', 57.0);
})->throws(InvalidArgumentException::class);

it('computes the PayMongo fee from centavos', function () {
    expect(EarningsModel::paymongoFee(440_000))->toBe(4400 * 0.035 + 15.0);
});

it('computes the embedding cost per document in pesos', function () {
    expect(EarningsModel::embeddingCostPerDocumentPesos(57.0))
        ->toBe(0.15 * 25_000 / 1_000_000 * 57.0);
});

it('computes a full plan earnings breakdown in pesos', function () {
    $e = EarningsModel::earnings(
        priceCents: 440_000,
        messageCap: 2_000,
        documentCap: 100,
        providerMix: ['gemini-flash' => 1.0],
        exchangeRate: 57.0,
    );

    expect($e['price_pesos'])->toBe(4400)
        ->and($e['messages'])->toBe(2_000)
        ->and($e['message_cost_pesos'])->toBe(round(EarningsModel::perMessageCostPesos('gemini-flash', 57.0), 2))
        ->and($e['embeddings_pesos'])->toBe(round(EarningsModel::embeddingCostPerDocumentPesos(57.0) * 100, 2))
        ->and($e['web_search_pesos'])->toBe(round(EarningsModel::webSearchCostPerQueryPesos(57.0) * 0.20 * 2_000, 2))
        ->and($e['ai_cogs_pesos'])->toBe(round(2_000 * EarningsModel::perMessageCostPesos('gemini-flash', 57.0), 2))
        ->and($e['paymongo_pesos'])->toBe(round(4400 * 0.035 + 15.0, 2));
});

it('counts assumed embeddings for uncapped plans', function () {
    $e = EarningsModel::earnings(
        priceCents: 890_000,
        messageCap: 10_000,
        documentCap: null,
        providerMix: ['gemini-flash' => 1.0],
        exchangeRate: 57.0,
        assumedDocuments: 500,
    );

    expect($e['embeddings_pesos'])
        ->toBe(round(EarningsModel::embeddingCostPerDocumentPesos(57.0) * 500, 2));
});

it('rejects a provider mix that does not sum to one', function () {
    EarningsModel::earnings(
        priceCents: 150_000,
        messageCap: 200,
        documentCap: 10,
        providerMix: ['gemini-flash' => 0.5, 'gpt-4o-mini' => 0.25],
        exchangeRate: 57.0,
    );
})->throws(InvalidArgumentException::class);

it('computes the firm break-even message count including embeddings', function () {
    $breakEven = EarningsModel::breakEvenMessages(
        priceCents: 890_000,
        documentCap: null,
        providerMix: ['gemini-flash' => 1.0],
        exchangeRate: 57.0,
        assumedDocuments: 500,
    );

    $fixedCosts = EarningsModel::paymongoFee(890_000)
        + EarningsModel::embeddingCostPerDocumentPesos(57.0) * 500;
    $perMessage = EarningsModel::perMessageCostPesos('gemini-flash', 57.0)
        + 0.20 * EarningsModel::webSearchCostPerQueryPesos(57.0);

    $expected = (int) ceil((890_000 / 100 - $fixedCosts) / $perMessage);

    expect($breakEven)->toBe($expected);
});

it('prints the earnings report in pesos', function () {
    $this->artisan('costing:earnings')
        ->expectsOutputToContain('Standard')
        ->expectsOutputToContain('Pro')
        ->expectsOutputToContain('Firm')
        ->expectsOutputToContain('₱/msg')
        ->assertExitCode(0);
});

it('pools the seat allowance across the organization', function () {
    $solo = EarningsModel::seatEarnings(
        pricePerSeatCents: 290_000, seats: 1, pooledMessagesPerSeat: 250,
        providerMix: ['claude-sonnet-5' => 1.0], exchangeRate: 57.0,
    );
    $five = EarningsModel::seatEarnings(
        pricePerSeatCents: 290_000, seats: 5, pooledMessagesPerSeat: 250,
        providerMix: ['claude-sonnet-5' => 1.0], exchangeRate: 57.0,
    );

    expect($five['allowance'])->toBe(1_250)
        ->and($five['seats'])->toBe(5)
        // One subscription per org, so the PayMongo fixed fee is not charged
        // five times over — per-seat economics improve slightly with size.
        ->and($five['net_per_seat_pesos'])->toBeGreaterThan($solo['net_per_seat_pesos']);
});

it('improves the seat margin when the pool is under-consumed', function () {
    $args = [
        'pricePerSeatCents' => 290_000, 'seats' => 5, 'pooledMessagesPerSeat' => 250,
        'providerMix' => ['claude-sonnet-5' => 1.0], 'exchangeRate' => 57.0,
    ];

    $full = EarningsModel::seatEarnings(...$args, utilisation: 1.0);
    $half = EarningsModel::seatEarnings(...$args, utilisation: 0.5);

    expect($half['messages_consumed'])->toBe(625)
        ->and($half['margin'])->toBeGreaterThan($full['margin']);
});

it('prefers an observed per-message cost over the token model', function () {
    $e = EarningsModel::seatEarnings(
        pricePerSeatCents: 290_000, seats: 2, pooledMessagesPerSeat: 100,
        providerMix: ['claude-sonnet-5' => 1.0], exchangeRate: 57.0,
        costPerMessagePesos: 9.99,
    );

    expect($e['message_cost_pesos'])->toBe(9.99)
        ->and($e['ai_cogs_pesos'])->toBe(round(9.99 * 200, 2));
});

// The command used to hardcode ₱57/USD while the app budgeted at
// config('billing.fx_usd_php'), so its margins were converted at a rate the
// product never used. It now takes the configured rate unless one is passed.
it('converts at the rate the app budgets with when no rate is given', function () {
    config(['billing.fx_usd_php' => 61.5]);

    $this->artisan('costing:earnings')
        ->expectsOutputToContain('₱61.50/USD')
        ->assertSuccessful();
});

it('lets an explicit exchange rate override the configured one', function () {
    config(['billing.fx_usd_php' => 61.5]);

    $this->artisan('costing:earnings', ['--exchange-rate' => 70])
        ->expectsOutputToContain('₱70.00/USD')
        ->assertSuccessful();
});

it('costs a budget-gated tier against its allowance, not a null message cap', function () {
    // Paid tiers carry no message count, so the old basis reported 0 messages
    // and therefore ₱0.00 of AI costs. The allowance is the real ceiling and is
    // translated back into the messages it buys.
    $this->artisan('costing:earnings')
        ->expectsOutputToContain('full monthly allowance is consumed')
        ->assertSuccessful();
});
