/**
 * The tone the public header and footer wear.
 *
 * They used to be the page's own background with a border, which on a dark
 * screen meant three shades of black stacked on each other — the chrome
 * disappeared into the content. These are a step off the page in both themes:
 * a cool paper in light, and a navy-tinted charcoal in dark that is visibly not
 * the near-black the body uses.
 *
 * IT IS THE BRAND HUE, and deliberately not a token. The dark tone is
 * hsl(221 42% 9%) against the sidebar's own hsl(219 41% 14%) — the same navy,
 * pushed darker. But the application DROPS that navy in dark mode: its sidebar
 * becomes a neutral hsl(0 0% 7%). So the landing is a little bluer in the dark
 * than the product is, on purpose: this page has no sidebar to carry the brand,
 * and three neutral blacks stacked on each other made the chrome vanish into
 * the content. Approved as a landing-only decision (2026-08-21) — do not
 * "fix" it by copying it back into app.css.
 *
 * Written as class strings in a module, the same way `@/lib/surfaces` does it,
 * so the header and the footer cannot drift apart — and so Tailwind still sees
 * the literals when it scans the source.
 */
export const CHROME_SURFACE = 'bg-[#f6f7f9] dark:bg-[#0d1320]';

/**
 * The same tone for the sticky header, which sits over the page and blurs what
 * passes under it — an opaque colour would make the backdrop filter pointless.
 */
export const CHROME_SURFACE_STICKY =
    'bg-[#f6f7f9]/85 backdrop-blur-md dark:bg-[#0d1320]/85';

/** The chrome's own border, a shade harder than the page's. */
export const CHROME_BORDER = 'border-[#e4e7ec] dark:border-[#1b2434]';
