<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Plan;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public landing page.
 *
 * The plans are read from the database rather than written into the Vue
 * component, because `plan_module` is deliberately data: a module can move
 * between Base, Pro and Institucional without a deploy (§4.3, EntitlementsSeeder).
 * A hardcoded comparison table would be wrong the first time somebody moves one,
 * and nobody would notice until a customer did.
 *
 * THE PRICES LIVE ON THE PAGE, THE COMPOSITION LIVES HERE. Since the
 * commercial decision was taken (Base gratuito em 2026/27, Pro 44,90 €/ano,
 * Institucional sob consulta, condição Fundador a 29,90 €/ano), the figures
 * are copy and sit with the rest of the copy in
 * `resources/js/components/landing/commercial.ts`. WHICH capabilities each
 * plan carries is not copy and never becomes copy: it stays here, read from
 * the entitlement tables, and the comparison table derives every ✓ from
 * `moduleKeys` below.
 *
 * `contactEmail` is a platform setting, not a constant: «Falar connosco» must
 * have a real destination, and when none is configured the card renders
 * without the button rather than pointing somewhere that does not answer.
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
            'contactEmail' => fn (): ?string => PlatformSetting::current()->publicContactEmail(),
        ]);
    }

    /**
     * Each plan, in its own order, with the entitlement keys it carries.
     *
     * KEYS, NOT DISPLAY NAMES. The comparison table asks «does this plan
     * carry `advanced_analytics`», not «is a module called Análises
     * Avançadas» — a table keyed on display names breaks silently the day
     * somebody renames one in the seeder, and renders a plan as missing a
     * capability it has.
     *
     * The human-readable names are not sent: the cards say what a plan does
     * in a teacher's words (`commercial.ts`), and a module name from the
     * catalogue is neither that nor useful next to it.
     *
     * @return list<array{key: string, name: string, moduleKeys: list<string>}>
     */
    protected function plans(): array
    {
        $cards = [];

        foreach (Plan::with('modules')->orderBy('sort_order')->get() as $plan) {
            $cards[] = [
                'key' => $plan->key,
                'name' => $plan->name,
                'moduleKeys' => array_values(
                    $plan->modules->map(fn (Module $module): string => $module->key)->all(),
                ),
            ];
        }

        return $cards;
    }
}
