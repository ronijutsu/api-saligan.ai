<?php

use App\Support\AdvisoryParser;

it('recovers the caveats a reply wrote as prose', function () {
    $reply = <<<'TEXT'
1. Direct answer: The sale is voidable.

2. Legal basis: RA 6657, Sec. 27 [SRC K3F9].

Caveats and next steps:
- The date of receipt of the demand letter is unconfirmed, so the 15-day period cannot be computed.
- The lot may still be within the ten-year alienation ban under the CLOA.

Sources
> "RA No. 6657, Sec. 27" [Link](https://example.gov.ph)
TEXT;

    $items = AdvisoryParser::fromReply($reply);

    expect($items)->toHaveCount(2)
        ->and($items[0]['title'])->toContain('date of receipt')
        ->and($items[1]['title'])->toContain('alienation ban')
        // The Sources section closes it — no source line leaks in as a caveat.
        ->and(collect($items)->pluck('title')->implode(' '))->not->toContain('RA No. 6657, Sec. 27"');
});

it('recognizes the section under its other headings', function () {
    foreach (['## Caveats', '**Limitations:**', '4. Caveats and next steps', 'Things to watch out for:'] as $heading) {
        expect(AdvisoryParser::hasSection("{$heading}\n- The tenancy status of the occupant was never established"))
            ->toBeTrue("heading not recognized: {$heading}");
    }
});

it('finds no section in a reply that has none', function () {
    $reply = "1. Direct answer: Yes.\n\nSources\n> \"RA No. 386, Art. 1191\"";

    expect(AdvisoryParser::hasSection($reply))->toBeFalse()
        ->and(AdvisoryParser::fromReply($reply))->toBe([]);
});

it('does not treat the next steps checklist as caveats', function () {
    // Next steps are tasks and belong to create_todo; picking them up here
    // would file every action item twice, once as a task and once as a caveat.
    $reply = <<<'TEXT'
Next Steps
- File the complaint with the RTC
- Pay the filing fees
TEXT;

    expect(AdvisoryParser::hasSection($reply))->toBeFalse();
});

it('stops at the next steps checklist that follows a caveats section', function () {
    $reply = <<<'TEXT'
Caveats:
- The property boundaries in the tax declaration do not match the TCT

Next Steps
- File the complaint with the RTC
TEXT;

    $items = AdvisoryParser::fromReply($reply);

    expect($items)->toHaveCount(1)
        ->and($items[0]['title'])->toContain('boundaries');
});

it('recovers paragraph-style caveats when the section has no bullets', function () {
    // Models that skip flag_advisories often write the section as plain
    // paragraphs rather than a bulleted list. Each blank-line-separated
    // paragraph is one caveat.
    $reply = <<<'TEXT'
Caveats and next steps

The exact conditions on the next page of the certificate were not provided; you should examine the full document to confirm any additional obligations.

Whether a secondary license is required depends on the specific business activities you plan to undertake; you may need to consult a lawyer or the SEC to determine if your intended activities fall under the regulated categories listed in Sections A-C.

Registrations with BIR, SSS, PhilHealth, and Pag-IBIG may involve separate forms, fees, and documentary requirements that are not described in the certificate; you should verify the current procedures with each agency.

This information is based solely on the uploaded certificate; it does not constitute legal advice. You should have a licensed Philippine lawyer review your compliance steps before proceeding.
TEXT;

    $items = AdvisoryParser::fromReply($reply);

    expect($items)->toHaveCount(4)
        ->and($items[0]['title'])->toContain('next page of the certificate')
        ->and($items[1]['title'])->toContain('secondary license')
        ->and($items[2]['title'])->toContain('Pag-IBIG');
});

it('joins wrapped lines into a single paragraph caveat', function () {
    $reply = <<<'TEXT'
Caveats
The date of receipt of the demand letter is unconfirmed,
so the 15-day period cannot be computed from the facts given.
TEXT;

    $items = AdvisoryParser::fromReply($reply);

    expect($items)->toHaveCount(1)
        ->and($items[0]['title'])->toContain('15-day period');
});

it('ignores short fragments in paragraph mode', function () {
    $reply = "Caveats\nSee above.";

    expect(AdvisoryParser::fromReply($reply))->toBe([]);
});

it('ignores free prose inside the section', function () {
    // A wrapped sentence would arrive as two truncated half-caveats, and half a
    // caveat shown as a real one is worse than none.
    $reply = <<<'TEXT'
Caveats:
This answer assumes several things about the transaction that were never
stated in your message or documents.
- The buyer's civil status is unstated, which affects the conjugal-consent requirement
TEXT;

    $items = AdvisoryParser::fromReply($reply);

    expect($items)->toHaveCount(1)
        ->and($items[0]['title'])->toContain('civil status');
});

it('never mines a drafted document body', function () {
    $reply = <<<'TEXT'
Caveats:
[[DOCUMENT_START]]
- WHEREAS, the parties agree to the following terms and conditions
[[DOCUMENT_END]]
TEXT;

    expect(AdvisoryParser::fromReply($reply))->toBe([]);
});

it('strips markdown emphasis and a leading label', function () {
    $items = AdvisoryParser::fromReply("Caveats:\n- **Deadline:** The appeal period lapses fifteen days from receipt");

    expect($items[0]['title'])->toBe('The appeal period lapses fifteen days from receipt');
});
