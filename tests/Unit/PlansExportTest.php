<?php

use App\Console\Commands\PlansExport;

/*
|--------------------------------------------------------------------------
| Plans export default target
|--------------------------------------------------------------------------
|
| The marketing site is generated from this command, so a stale default path
| fails silently at the worst moment: after a plan change, when the operator
| believes the landing table was refreshed. The sibling checkout is
| `landing-batayan`; asserting the resolved path catches a regression to the
| old `landing` directory without needing a database or the sibling repo.
|
*/

it('defaults the marketing export to the landing-batayan data directory', function (): void {
    $path = app(PlansExport::class)->defaultPath();

    expect($path)
        ->toEndWith('landing-batayan/src/data/plans.json')
        ->not->toContain('/landing/src/');
});

it('exposes the same default in the copy-paste guidance', function (): void {
    expect(PlansExport::DEFAULT_RELATIVE_PATH)
        ->toBe('../landing-batayan/src/data/plans.json')
        ->and(app(PlansExport::class)->defaultPath())
        ->toEndWith(PlansExport::DEFAULT_RELATIVE_PATH);
});
