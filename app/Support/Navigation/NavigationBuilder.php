<?php

namespace App\Support\Navigation;

use App\Support\Entitlements\Entitlements;
use Illuminate\Support\Facades\Route;

/**
 * Turns config/navigation.php into the menu this organization actually sees.
 *
 * Items whose module the organization is not entitled to are dropped here, so a
 * Base teacher is never even sent the Pro items. This is presentation — the real
 * gate is the `module:` middleware on each route (§8.2) — but it uses the same
 * Entitlements service, so the two cannot disagree.
 */
class NavigationBuilder
{
    public function __construct(protected Entitlements $entitlements) {}

    /**
     * @return array{sections: list<array{label: ?string, items: list<array<string, mixed>>}>, footer: list<array<string, mixed>>}
     */
    public function forCurrentOrganization(): array
    {
        $sections = [];

        foreach (config('navigation.sections') as $section) {
            $items = $this->allowedItems($section['items']);

            if ($items !== []) {
                $sections[] = ['label' => $section['label'], 'items' => $items];
            }
        }

        return [
            'sections' => $sections,
            'footer' => $this->allowedItems(config('navigation.footer')),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    protected function allowedItems(array $items): array
    {
        $allowed = [];

        foreach ($items as $item) {
            if ($item['module'] !== null && ! $this->entitlements->allows($item['module'])) {
                continue;
            }

            $routeName = $item['route'] ?? $item['key'];

            $allowed[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'icon' => $item['icon'],
                'phase' => $item['phase'],
                // Not-yet-built pages still resolve — routes/app.php registers a
                // placeholder route per item — so the menu never links to a 404.
                'href' => Route::has($routeName) ? route($routeName) : null,
                'built' => $item['phase'] === 0,
            ];
        }

        return $allowed;
    }
}
