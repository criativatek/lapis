import { capitalizeFirst } from '@/lib/text';

/**
 * Datas de calendário («2026-09-28») ditas em pt-PT. Ancoradas ao meio-dia UTC
 * da própria data, como o resto das aulas faz, para que nenhuma mudança de hora
 * desloque um dia.
 */
function anchor(date: string): Date {
    return new Date(`${date}T12:00:00Z`);
}

const longDay = new Intl.DateTimeFormat('pt-PT', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
});
const fullDay = new Intl.DateTimeFormat('pt-PT', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});
/**
 * As abreviaturas da escola («seg», «ter»…), escritas aqui e não pedidas ao
 * Intl: o «short» do pt-PT varia entre browsers («seg.», «segunda»), e uma
 * fila de cinco dias no telemóvel precisa de três letras certas.
 */
const WEEKDAY_ABBREVIATIONS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
const dayMonth = new Intl.DateTimeFormat('pt-PT', {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
});

/** «Segunda-feira, 28 de setembro». */
export function dayHeading(date: string): string {
    return capitalizeFirst(longDay.format(anchor(date)));
}

/** «Quinta-feira, 1 de outubro de 2026» — a data por extenso, com ano. */
export function fullDateLabel(date: string): string {
    return capitalizeFirst(fullDay.format(anchor(date)));
}

/** «seg» */
export function weekdayAbbr(date: string): string {
    return WEEKDAY_ABBREVIATIONS[anchor(date).getUTCDay()] ?? '';
}

/** «28/09». */
export function dayNumber(date: string): string {
    return String(anchor(date).getUTCDate());
}

/** «28 set.» */
export function dayMonthLabel(date: string): string {
    return dayMonth.format(anchor(date));
}

/** «seg., 28 set.» */
export function shortDayLabel(date: string): string {
    return `${weekdayAbbr(date)}, ${dayMonthLabel(date)}`;
}

export function addDays(date: string, days: number): string {
    const value = anchor(date);
    value.setUTCDate(value.getUTCDate() + days);

    return value.toISOString().slice(0, 10);
}

/** «28 set. – 4 out.» */
export function weekRangeLabel(start: string, end: string): string {
    return `${dayMonthLabel(start)} – ${dayMonthLabel(end)}`;
}
