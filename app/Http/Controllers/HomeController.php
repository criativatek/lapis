<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public landing page.
 *
 * The plan cards are read from the database rather than written into the Vue
 * component, because `plan_module` is deliberately data: a module can move
 * between Base, Pro and Institucional without a deploy (§4.3, EntitlementsSeeder).
 * A hardcoded pricing table would be wrong the first time somebody moves one,
 * and nobody would notice until a customer did.
 *
 * NO PRICE IS SENT, because none exists. The commercial composition of the
 * plans is on the «do not do without asking» list (CLAUDE.md §31), so the page
 * shows what each plan CARRIES and leaves the figure to be announced.
 *
 * A signed-in visitor still gets the landing page — no redirect. The header
 * swaps its buttons for «Ir para o painel» instead, which is both what someone
 * arriving from a shared link expects and the only shape that cannot produce a
 * redirect loop with the dashboard (§32 of the brief).
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Welcome', [
            'plans' => fn (): array => $this->plans(),
        ]);
    }

    /**
     * Each plan with the modules it carries, and — from the second plan on —
     * only what it ADDS to the one below it. Pricing cards read «tudo do Base,
     * mais…», and deriving that here means the "mais…" list can never drift
     * from what the entitlement tables actually grant.
     *
     * @return list<array{key: string, name: string, modules: list<string>, adds: list<string>}>
     */
    protected function plans(): array
    {
        $plans = Plan::with('modules')->orderBy('sort_order')->get();

        $previous = [];
        $cards = [];

        foreach ($plans as $plan) {
            $modules = $plan->modules->pluck('name')->all();

            $cards[] = [
                'key' => $plan->key,
                'name' => $plan->name,
                'modules' => array_values($modules),
                'adds' => array_values(array_diff($modules, $previous)),
            ];

            $previous = $modules;
        }

        return $cards;
    }
}
