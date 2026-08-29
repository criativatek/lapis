<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use App\Support\Landing\PlanCards;
use App\Support\Legal\LegalDocuments;
use App\Support\Seo\PublicPages;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The marketing site beyond the landing page. Public, no tenant.
 *
 * ONE PAGE PER SEARCH INTENT. The landing used to be the only indexable page
 * and tried to rank for «avaliação de alunos», «gestão de turmas», «sumários»
 * and «relatórios» at once — which is how a page ranks for none of them. Each
 * feature page has its own H1, title and description (PublicPages) and its
 * own copy (resources/js/components/marketing/features.ts).
 */
class MarketingController extends Controller
{
    public function feature(string $slug): Response
    {
        abort_unless(in_array($slug, PublicPages::FEATURE_SLUGS, true), 404);

        return Inertia::render('marketing/Feature', [
            'slug' => $slug,
            'seoTitle' => $this->seoTitle('marketing/Feature', '/funcionalidades/'.$slug),
            'contactEmail' => fn (): ?string => PlatformSetting::current()->publicContactEmail(),
        ]);
    }

    public function plans(): Response
    {
        return Inertia::render('marketing/Plans', [
            'seoTitle' => $this->seoTitle('marketing/Plans', '/planos'),
            'plans' => fn (): array => PlanCards::all(),
            'contactEmail' => fn (): ?string => PlatformSetting::current()->publicContactEmail(),
        ]);
    }

    public function security(): Response
    {
        return Inertia::render('marketing/Security', [
            'seoTitle' => $this->seoTitle('marketing/Security', '/seguranca'),
            'contactEmail' => fn (): ?string => PlatformSetting::current()->publicContactEmail(),
        ]);
    }

    public function about(): Response
    {
        // The same identity the legal pages show — a school reading /sobre and
        // /privacidade must find the same entity on both.
        $controller = LegalDocuments::controller();

        return Inertia::render('marketing/About', [
            'seoTitle' => $this->seoTitle('marketing/About', '/sobre'),
            'entity' => [
                'name' => $controller['name'],
                'vat' => $controller['vat'],
                'address' => $controller['address'],
                'privacyEmail' => $controller['privacy_email'],
                'supportEmail' => config('lapis.legal.support_email'),
            ],
            'contactEmail' => fn (): ?string => PlatformSetting::current()->publicContactEmail(),
        ]);
    }

    /**
     * The <title> the page's Vue <Head> sets. WITH SSR ON, THE VUE <Head>
     * WINS over the blade <title> in the served HTML — so the two must be the
     * same string, and this is where the page gets it from.
     */
    private function seoTitle(string $component, string $path): string
    {
        return PublicPages::current($component, $path)['title'] ?? config('app.name');
    }
}
