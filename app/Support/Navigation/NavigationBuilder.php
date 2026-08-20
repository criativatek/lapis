<?php

namespace App\Support\Navigation;

use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Turns config/navigation.php into the menu this organization actually sees.
 *
 * Items whose module the organization is not entitled to are dropped here, so a
 * Base teacher is never even sent the Pro items. This is presentation — the real
 * gate is the `module:` middleware on each route (§8.2) — but it uses the same
 * Entitlements service, so the two cannot disagree.
 *
 * `owner_only` items (Fatia 3's Equipa) go a step further: entitlement is a
 * property of the ORGANIZATION's plan, not of who is asking, so it cannot by
 * itself keep a member from seeing a link only the owner may use. Same
 * caveat as the module gate — this is presentation. TeamController's own
 * Policy check is the actual authority.
 */
class NavigationBuilder
{
    public function __construct(protected Entitlements $entitlements, protected CurrentOrganization $currentOrganization) {}

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

            if (! empty($item['owner_only']) && ! $this->isOwnerOfCurrentOrganization()) {
                continue;
            }

            $routeName = $item['route'] ?? $item['key'];

            $allowed[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                // One line saying what the page is for, shown in the sidebar
                // tooltip. Null on the core items, which need no explaining.
                'description' => $item['description'] ?? null,
                // Extra paths this entry answers for. «Turma» is one menu item
                // over three historical routes — the reading, the grid and the
                // synthesis — and all three should light it up (§36).
                'match' => $item['match'] ?? [],
                'icon' => $item['icon'],
                'phase' => $item['phase'],
                // Not-yet-built pages still resolve — routes/app.php registers a
                // placeholder route per item — so the menu never links to a 404.
                'href' => Route::has($routeName) ? route($routeName) : null,
                'built' => $item['phase'] === 0 || ! empty($item['built']),
            ];
        }

        return $allowed;
    }

    protected function isOwnerOfCurrentOrganization(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $this->currentOrganization->isResolved()
            && $user->owns($this->currentOrganization->get());
    }
}
