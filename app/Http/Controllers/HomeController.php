<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
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
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Welcome', [
            'contactEmail' => fn (): ?string => PlatformSetting::current()->publicContactEmail(),
        ]);
    }
}
