<?php

use App\Http\Controllers\PlaceholderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Teacher area
|--------------------------------------------------------------------------
|
| Registers one route per navigation item in config/navigation.php, each behind
| the same `module:` gate the menu uses. Items whose phase is 0 (already built,
| e.g. the dashboard) are skipped — their real routes live in routes/web.php.
| Everything else renders the placeholder until its phase delivers the real page,
| at which point that item moves out of this loop into its own routes file.
|
| Generating routes and menu from the same list means a gated menu item always
| has a gated route behind it, and neither can drift from the other.
|
*/

Route::middleware(['auth', 'verified', 'organization'])->group(function () {
    /** @var list<array{key: string, module: ?string, label: string, phase: int}> $items */
    $items = [];
    foreach (config('navigation.sections') as $section) {
        $items = [...$items, ...$section['items']];
    }
    $items = [...$items, ...config('navigation.footer')];

    foreach ($items as $item) {
        // phase 0 = already built elsewhere (dashboard, settings). Don't shadow it.
        if ($item['phase'] === 0) {
            continue;
        }

        $route = Route::get($item['key'], PlaceholderController::class)
            ->name($item['key'])
            ->defaults('navLabel', $item['label'])
            ->defaults('navPhase', $item['phase']);

        if ($item['module'] !== null) {
            $route->middleware("module:{$item['module']}");
        }
    }
});
