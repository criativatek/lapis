<?php

namespace App\Support\Ssr;

/**
 * Which pages the SSR server is asked to render.
 *
 * SSR entered in 0.93.0 for ONE reason: the public pages had to be readable by
 * crawlers that never run JavaScript (see resources/js/ssr.ts). But
 * `config('inertia.ssr.enabled')` is a global switch, so from that release on
 * EVERY Inertia response — the dashboard, a report, a grading grid — was also
 * sent to the Node process, and with it the whole authenticated shell:
 * AppSidebar reading `page.props.nav`, ContextBar reading
 * `page.props.selectableAcademicYears`, the header reading `auth.user`. That
 * shell predates SSR (the sidebar is from 2026-07-18) and was written for a
 * browser that always has those props. When a page object reaches the Node
 * server without them, it dies exactly the way ssr.log recorded — «Cannot
 * read properties of undefined (reading 'footer' / 'sections' / 'length')» —
 * for a reader who is logged in, has JavaScript, and gains nothing from SSR.
 *
 * So SSR is scoped back to what it was built for. The rule is the SAME one
 * `resolveLayout()` in resources/js/inertia.ts applies to decide that a page
 * has no app shell: `Welcome`, `marketing/*`, `legal/*`. Keep the two in
 * step — a page that gets the shell must not get SSR, and a page that is
 * meant for crawlers must appear here or it is served as a 13 KB JS shell.
 *
 * Print views (`* /Print`) also have no shell but are authenticated documents
 * nobody indexes; rendering them in Node would be load for nothing.
 */
final class ServerRenderedPages
{
    /**
     * Component names rendered on the server, exactly.
     *
     * @var list<string>
     */
    public const PAGES = ['Welcome'];

    /**
     * Component name prefixes rendered on the server.
     *
     * @var list<string>
     */
    public const PREFIXES = ['marketing/', 'legal/'];

    public static function wants(?string $component): bool
    {
        if ($component === null || $component === '') {
            return false;
        }

        if (in_array($component, self::PAGES, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($component, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
