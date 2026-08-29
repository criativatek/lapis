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
            'contactEmail' => fn (): ?string => PlatformSetting::current()->publicContactEmail(),
        ]);
    }

    public function plans(): Response
    {
        return Inertia::render('marketing/Plans', [
            'plans' => fn (): array => PlanCards::all(),
            'contactEmail' => fn (): ?string => PlatformSetting::current()->publicContactEmail(),
        ]);
    }

    public function security(): Response
    {
        return Inertia::render('marketing/Security', [
            'contactEmail' => fn (): ?string => PlatformSetting::current()->publicContactEmail(),
        ]);
    }

    public function about(): Response
    {
        // The same identity the legal pages show — a school reading /sobre and
        // /privacidade must find the same entity on both.
        $controller = LegalDocuments::controller();

        return Inertia::render('marketing/About', [
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
}
