/**
 * How a RECORDED result state is named to a teacher.
 *
 * One map, because two screens now say these words: the ⚠ on Resultados and the
 * partial-coverage note on the INOVAR export. Two copies is how «Ausência
 * justificada» becomes «Falta justificada» on one of them.
 *
 * A state missing from this map gets neutral wording rather than an invented
 * one. That matters most for the states that are NOT here: a question nobody
 * has graded yet is `pending`, and calling it «Ausente» would be inventing an
 * event — in LÁPIS a blank is not a zero and no score is not an absence.
 */
export const RESULT_STATE_LABELS: Record<string, string> = {
    absent: 'Ausência',
    absent_justified: 'Ausência justificada',
    exempt: 'Dispensa',
    not_applicable: 'Não aplicável',
    // «Anulado» alone reads as though the student was annulled. Beside a date
    // and an instrument it has to name what was annulled: the element.
    annulled: 'Elemento anulado',
    under_review: 'Em revisão',
};

/**
 * The state as words, or a neutral phrase when it is one this does not name.
 *
 * «Sem registo de avaliação» describes what is known — that the element carries
 * no classification — and claims nothing about why.
 */
export function resultStateLabel(state: string): string {
    return RESULT_STATE_LABELS[state] ?? 'Sem registo de avaliação';
}

/** «Ficha de avaliação — 12/11/2026 — Ausência justificada» */
export function coverageElementLine(element: { instrument: string; applied_on: string; reason: string }): string {
    const date = element.applied_on === '' ? 'data não registada' : element.applied_on;

    return `${element.instrument} — ${date} — ${resultStateLabel(element.reason)}`;
}
