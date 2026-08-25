/**
 * The shapes the «Calendário do Ano Letivo» server sends, and the one piece of
 * visual vocabulary its two views share.
 *
 * Both views read the same two things and nothing else: the year's own períodos
 * (structural context) and the avaliações applied on a date (Instrument). There
 * is deliberately no lesson type here — aulas belong to «Horário do Professor»,
 * not to this calendar.
 */

export type CalendarAssessment = {
    ulid: string;
    title: string;
    applied_on: string;
    class_ulid: string;
    class_label: string;
    subject: string;
    type: string;
    status: string;
    status_label: string;
    href: string;
};

export type CalendarPeriod = {
    ulid: string;
    label: string;
    kind: string;
    kind_label: string;
    sequence: number;
    starts_on: string;
    ends_on: string;
};

export type CalendarDay = {
    date: string;
    day: number;
    in_month: boolean;
    is_today: boolean;
    period: CalendarPeriod | null;
    assessments: CalendarAssessment[];
};

/**
 * The tints the período BANDS are drawn in, in the order the períodos of the
 * year run. Deliberately quiet, and deliberately never the only thing that
 * separates a período from an avaliação: a band has no border, no icon and no
 * emphasis, while an avaliação has all three, so the two stay distinguishable
 * on a monochrome screen and for a reader who does not see the difference in
 * hue. Within the structure itself the tint is a convenience — each band is
 * also named, in the legend and where it begins.
 *
 * There is no setting behind this: one deliberate treatment, applied the same
 * way in both views.
 */
const TINTS = [
    'bg-sky-100/70 dark:bg-sky-950/40',
    'bg-amber-100/70 dark:bg-amber-950/40',
    'bg-emerald-100/70 dark:bg-emerald-950/40',
    'bg-violet-100/70 dark:bg-violet-950/40',
    'bg-rose-100/70 dark:bg-rose-950/40',
    'bg-teal-100/70 dark:bg-teal-950/40',
];

export function periodTint(periods: CalendarPeriod[], ulid: string): string {
    const index = periods.findIndex((period) => period.ulid === ulid);

    return index === -1 ? '' : (TINTS[index % TINTS.length] as string);
}
