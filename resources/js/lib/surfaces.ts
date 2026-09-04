/**
 * The surfaces of the Estatística dashboard.
 *
 * The page used to be white cards on a white page, which is why «before» and
 * «after» kept looking alike however the contents were rearranged: nothing
 * about the FIELD had changed. These are the tinted grounds that give each
 * card an identity before a single number is read.
 *
 * VERY PALE ON PURPOSE. A tint at 50–60% of an already-light Tailwind 50 step
 * reads as paper stock rather than as a status colour — which matters, because
 * on this page saturated colour already means something: the scale's tones mean
 * performance and the trend inks mean movement. A surface must never be
 * mistaken for either, so these are weaker than both by a wide margin.
 *
 * Each has a dark twin at low opacity: on a dark ground a pastel wash turns to
 * mud, so the dark variants are deep and desaturated instead, and carry their
 * identity through the border rather than the fill.
 */

/**
 * CONVENÇÃO DE ADOPÇÃO (2026-09-04, plano «mais cor»): para superfícies NOVAS
 * fora do Estatística usar apenas `amber` / `mint` / `sky` / `plain` — a
 * paleta do `.impeccable.md` (âmbar marca, esmeralda dados, céu informação).
 * `violet` e `rose` ficam pelos ecrãs de Estatística que já os usam; não se
 * espalham nem se apagam — churn sem ganho.
 */
export type SurfaceTone = 'amber' | 'violet' | 'mint' | 'sky' | 'rose' | 'plain';

/** Card ground + border, tinted. */
export const SURFACE: Record<SurfaceTone, string> = {
    amber: 'bg-amber-50/70 border-amber-200/60 dark:bg-amber-950/20 dark:border-amber-900/40',
    violet: 'bg-violet-50/70 border-violet-200/60 dark:bg-violet-950/20 dark:border-violet-900/40',
    mint: 'bg-emerald-50/60 border-emerald-200/60 dark:bg-emerald-950/20 dark:border-emerald-900/40',
    sky: 'bg-sky-50/60 border-sky-200/60 dark:bg-sky-950/20 dark:border-sky-900/40',
    rose: 'bg-rose-50/50 border-rose-200/50 dark:bg-rose-950/20 dark:border-rose-900/40',
    plain: 'bg-card border-border/70',
};

/** The shared card shape: generous radius, soft edge, no heavy shadow. */
export const CARD = 'rounded-2xl border shadow-sm';

/** A card, on one of the tinted grounds. */
export function card(tone: SurfaceTone = 'plain'): string {
    return `${CARD} ${SURFACE[tone]}`;
}

/**
 * An inner surface that sits ON a tinted card without muddying it — a paler
 * layer of the same paper rather than a second colour.
 */
export const INSET = 'rounded-xl bg-background/60 dark:bg-background/30';

/**
 * The page's own ground: warm, and just off-white enough that the cards read as
 * objects placed on it rather than as holes cut out of it.
 */
export const PAGE = 'bg-[#fbfaf7] dark:bg-background';
