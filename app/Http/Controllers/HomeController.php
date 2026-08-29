<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Support\Commercial\FounderAvailability;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public landing page.
 *
 * The plans are read from the database rather than written into the Vue
 * component, because the composition is deliberately data: a module can move
 * between Base, Pro and Institucional without a deploy (§4.3, EntitlementsSeeder).
 * A hardcoded comparison table would be wrong the first time somebody moves one,
 * and nobody would notice until a customer did.
 *
 * ALWAYS THE CURRENT PUBLISHED VERSION, never a subscriber's fixed one
 * (ADR-0008 §5). The landing sells what is on sale today; a visitor reading it
 * is not yet anybody's grandfathered customer.
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
    public function __construct(protected FounderAvailability $founder) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('Welcome', [
            'plans' => fn (): array => $this->plans(),
            'contactEmail' => fn (): ?string => PlatformSetting::current()->publicContactEmail(),
            // WHETHER THE LAUNCH CONDITION IS STILL OPEN — the one commercial
            // figure on this page the server has to answer, because it is the
            // only one that stops being true on its own. «Faça parte dos
            // primeiros 250» and «disponível até 31 de dezembro de 2026» are a
            // promise with two expiry conditions, and until now the page went
            // on making it regardless: the day the seats ran out or the
            // deadline passed, the landing would still have offered 29,90 € to
            // whoever read it. The seats are really counted now
            // (`App\Support\Commercial\FounderSeats`), so this is a fact rather
            // than a guess.
            //
            // A BOOLEAN, NOT A COUNT. `LandingFounder` documents why there is
            // no «restam 37» on this page, and that reasoning has not changed:
            // manufactured scarcity is a marketing device, and putting a real
            // number there is a commercial decision rather than a correctness
            // fix. This says only «still available» or «no longer», which is
            // the minimum that stops the page stating something false.
            'founder' => fn (): array => ['open' => $this->founder->isOpen()],
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

        foreach (Plan::with('currentVersion.modules')->orderBy('sort_order')->get() as $plan) {
            // A plan with nothing published yet shows no capabilities rather
            // than blowing up a public page. It cannot happen in a seeded
            // installation, and a landing that 500s is the worse of the two
            // failures.
            $version = $plan->currentVersionOrNull();

            $cards[] = [
                'key' => $plan->key,
                'name' => $plan->name,
                'moduleKeys' => $version === null ? [] : array_values(
                    $version->modules->map(fn (Module $module): string => $module->key)->all(),
                ),
            ];
        }

        return $cards;
    }
}
