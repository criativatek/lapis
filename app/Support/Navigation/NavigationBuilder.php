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
 * Items whose module the organization cannot even READ are dropped here, so a
 * Base teacher is never even sent the Pro items. This is presentation — the real
 * gate is the `module:` middleware on each route (§8.2) — but it uses the same
 * Entitlements service, so the two cannot disagree.
 *
 * Visibility is `canRead()`, not `allows()`, and the difference is the whole
 * point of the three access states: a suspended organization's modules resolve
 * to `ReadOnly`, which `RequireModule` still serves GETs for. Filtering the
 * menu by `allows()` would hide exactly the pages that suspension is supposed
 * to leave consultable — reachable by typed URL but not by navigation, which is
 * not what "os dados não desaparecem" means. `allows()`/`modules()` keep their
 * Allowed-only meaning and stay the right question for anything that WRITES;
 * this asks the weaker question because it is only deciding what to show.
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
            $items = $this->visibleItems($section['items']);

            if ($items !== []) {
                $sections[] = ['label' => $section['label'], 'items' => $items];
            }
        }

        return [
            'sections' => $sections,
            'footer' => $this->visibleItems(config('navigation.footer')),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    protected function visibleItems(array $items): array
    {
        $visible = [];

        foreach ($items as $item) {
            if ($item['module'] !== null && ! $this->entitlements->canRead($item['module'])) {
                continue;
            }

            if (! empty($item['owner_only']) && ! $this->isOwnerOfCurrentOrganization()) {
                continue;
            }

            $routeName = $item['route'] ?? $item['key'];

            $visible[] = [
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

        return $visible;
    }

    protected function isOwnerOfCurrentOrganization(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $this->currentOrganization->isResolved()
            && $user->owns($this->currentOrganization->get());
    }
}
