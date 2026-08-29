<?php

namespace App\Http\Controllers;

use App\Support\Seo\LandingSeo;
use App\Support\Seo\PublicPages;
use Illuminate\Http\Response;

/**
 * `robots.txt` and `sitemap.xml`.
 *
 * THEY ARE ROUTES, NOT FILES IN `public/`, for one reason: both have to name
 * the site's own address, and a static file cannot know it. A `robots.txt`
 * committed with `https://lapispro.com` in it would announce production's
 * sitemap from every local and staging install, and the first person to copy
 * the repo for a second deployment would point a new site's crawl budget at
 * the old one.
 *
 * BOTH ANSWER WITH `LandingSeo::canonical()`, not with `url('/')`. This
 * installation is served on two domains, and `url()` follows the request —
 * so a sitemap built from it advertised a different site depending on which
 * host asked for it, and the `Sitemap:` line in robots.txt did the same. One
 * site has one sitemap.
 *
 * THE OLD `public/robots.txt` HAD TO GO. The web server answers with a real
 * file before Laravel ever sees the request, so leaving it there would have
 * meant this route never ran and nobody noticing.
 *
 * The sitemap lists ONE url, because one public page exists. A sitemap padded
 * with `/login` and `/register` — which the meta robots on those pages now
 * tells crawlers to skip — would be a sitemap contradicting itself.
 */
class SeoController extends Controller
{
    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /$',
            '',
            // Not access control — the app enforces that. This keeps a crawler
            // out of pages that will only ever answer it with a login form,
            // and out of the two signed public routes that must never be
            // indexed (see the noindex in app.blade.php, which is the part
            // that actually binds).
            'Disallow: /admin',
            'Disallow: /auto/',
            'Disallow: /dashboard',
            'Disallow: /invitations/',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /settings',
            '',
            // Explícito, apesar de o `Allow: /$` acima só cobrir a raiz: cada
            // página pública é encontrável sem passar pela landing.
            ...array_map(fn (array $page): string => 'Allow: '.$page['path'], array_values(array_filter(PublicPages::all(), fn (array $page): bool => $page['path'] !== '/'))),
            '',
            'Sitemap: '.LandingSeo::canonical().'/sitemap.xml',
            '',
        ];

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function sitemap(): Response
    {
        // Every public page, from the one list (PublicPages). Frequency and
        // priority are declared there, next to the page they describe.
        $pages = array_map(fn (array $page): array => [
            'loc' => PublicPages::url($page['path']),
            'changefreq' => $page['changefreq'],
            'priority' => $page['priority'],
        ], PublicPages::all());

        $entries = '';

        foreach ($pages as $page) {
            $entries .= <<<XML

                <url>
                    <loc>{$this->escape($page['loc'])}</loc>
                    <changefreq>{$page['changefreq']}</changefreq>
                    <priority>{$page['priority']}</priority>
                </url>
            XML;
        }

        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">{$entries}
        </urlset>

        XML;

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    /**
     * A `<loc>` is XML, and a configured public url carrying a `&` in a query
     * string would otherwise produce a document no parser accepts.
     */
    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
