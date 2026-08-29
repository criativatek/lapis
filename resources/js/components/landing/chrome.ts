/**
 * The tone the public header and footer wear.
 *
 * They used to be the page's own background with a border, which on a dark
 * screen meant three shades of black stacked on each other — the chrome
 * disappeared into the content. These are a step off the page in both themes:
 * warm paper in light, and warm brown-black in dark.
 *
 * IT IS THE BRAND AMBER, carried far enough to be read as a colour rather than
 * as a tint. The mark's pencil body is hsl(38 88% 65%); the light ground is
 * hsl(36 75% 93%) and the dark one hsl(28 55% 13%) — same family, one at paper
 * lightness and one at ink darkness. The fully saturated amber stays where it
 * means something: the logo, the accent rules, the highlight in the tour.
 *
 * Not a token, and not in app.css: the application's own chrome is neutral in
 * dark mode, and this warmth is a landing-only decision (2026-08-21). Do not
 * "fix" it by copying it back into the design system.
 *
 * THE TEXT TONE TRAVELS WITH THE GROUND. `text-muted-foreground` is a neutral
 * grey chosen against white; on a peach ground it falls to 4.2:1, under the
 * 4.5:1 that normal text needs. CHROME_MUTED is warm, matches the ground, and
 * measures 4.9:1 in light and 6.8:1 in dark. Saturating the chrome without
 * moving the text would have made the footer quietly unreadable.
 */
// 2026-08-29: the public pages are light only and white (design/landing-light).
// The warm paper described above survives only in the dark variants, which
// app.blade.php never activates on these pages — kept so the tokens stay
// coherent if the lock is ever lifted.
export const CHROME_SURFACE = 'bg-white dark:bg-[#33200f]';

/**
 * The same tone for the sticky header, which sits over the page and blurs what
 * passes under it — an opaque colour would make the backdrop filter pointless.
 */
export const CHROME_SURFACE_STICKY =
    'bg-white/85 backdrop-blur-md dark:bg-[#33200f]/85';

/** The chrome's own border, a shade harder than the ground. */
export const CHROME_BORDER = 'border-border dark:border-[#47321f]';

/** Secondary text on the chrome. Warm, and above 4.5:1 in both themes. */
export const CHROME_MUTED = 'text-muted-foreground dark:text-[#b4aa9c]';

/** Primary text on the chrome. */
export const CHROME_FOREGROUND = 'text-foreground dark:text-[#f5efe6]';

/** A secondary link on the chrome: muted at rest, primary under the pointer. */
export const CHROME_LINK =
    'text-muted-foreground hover:text-foreground dark:text-[#b4aa9c] dark:hover:text-[#f5efe6]';

/**
 * The ghost button's own hover, because the shared `accent` token is a pale
 * amber picked against white — on this ground the two are within a hair of
 * each other and the hover reads as nothing happening.
 */
export const CHROME_GHOST_HOVER = 'hover:bg-blue-50 dark:hover:bg-[#432c16]';
