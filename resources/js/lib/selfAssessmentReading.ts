/**
 * How one reading of a student's self-assessment is written on screen.
 *
 * A reading is the student's own answer, in the scale it was answered on. Three
 * situations exist and none of them ever converts a value:
 *   · the answer was given under the criteria the class uses today;
 *   · it was given under EARLIER criteria (`previous_criteria`) — another scale,
 *     or the same scale with other domains or weights;
 *   · the newest self-assessment has no global answer and the one shown is an
 *     earlier one (`earlier`) — a reference, never the current answer.
 *
 * The server decides which of the three it is; this file only writes it down,
 * the same way in every screen.
 */

export type SelfAssessmentReading = {
    code: string;
    label: string;
    /** What a cell shows: a level's code, «70%», a number. Absent in sheets saved before it existed — `code` stands in. */
    display?: string;
    /** Null for a numeric answer: there is no band to rank. */
    sequence: number | null;
    is_negative: boolean;
    scale?: string | null;
    scale_id?: number | null;
    /** Answered under criteria other than those the class uses now. */
    previous_criteria?: boolean;
    /** «Avaliação final · versão 1 · Percentagem (0 a 100)» — the criteria it was answered under. */
    criteria?: string | null;
    /** The newest self-assessment has no global answer; this one is from an earlier self-assessment. */
    earlier?: boolean;
} | null;

/** The marker a compact cell carries; the full sentence lives in the accessible name and in the legend. */
export const EARLIER_MARK = '(ant.)';

export const EARLIER_LEGEND_MEANING = 'autoavaliação respondida com critérios anteriores aos atuais da turma, ou proposta anterior quando a nova não tem proposta global.';

export const EARLIER_LEGEND = `${EARLIER_MARK} — ${EARLIER_LEGEND_MEANING}`;

/** The value as a cell shows it. */
export function displayOf(reading: NonNullable<SelfAssessmentReading>): string {
    return reading.display ?? reading.code;
}

/** True when the value must not be read as being on the scale the class uses today. */
export function isOtherCriteria(reading: SelfAssessmentReading | undefined): boolean {
    return reading !== null && reading !== undefined && (reading.previous_criteria === true || reading.earlier === true);
}

/** True when any of the readings carries the «(ant.)» marker — what decides whether a screen shows the legend. */
export function anyOtherCriteria(readings: Iterable<SelfAssessmentReading | undefined>): boolean {
    for (const reading of readings) {
        if (isOtherCriteria(reading)) {
            return true;
        }
    }

    return false;
}

/** The value written out in a sentence or a list: «70% · critérios anteriores», «anterior: 70% · critérios anteriores». */
export function fullText(reading: NonNullable<SelfAssessmentReading>): string {
    const value = displayOf(reading);
    const suffix = reading.previous_criteria === true ? ' · critérios anteriores' : '';

    return reading.earlier === true ? `anterior: ${value}${suffix}` : `${value}${suffix}`;
}

/** The value with its mention when the scale has one: «4 — Bom», but just «70%» where the label would repeat it. */
export function namedValue(reading: NonNullable<SelfAssessmentReading>): string {
    const value = displayOf(reading);

    return reading.label !== value ? `${value} — ${reading.label}` : value;
}

/**
 * The reading for a place with room to write it — a panel, a tooltip, a list:
 * the mention on today's criteria, the «critérios anteriores» / «anterior» wording otherwise.
 */
export function detailText(reading: NonNullable<SelfAssessmentReading>): string {
    return isOtherCriteria(reading) ? fullText(reading) : namedValue(reading);
}

/** `fullText` plus the criteria it was answered under — for paper, where no tooltip exists. */
export function printText(reading: NonNullable<SelfAssessmentReading>): string {
    if (!isOtherCriteria(reading)) {
        return namedValue(reading);
    }

    return reading.criteria ? `${fullText(reading)} (${reading.criteria})` : fullText(reading);
}

/** The value for a narrow cell: the value itself, plus «(ant.)» when it is not on today's criteria. */
export function compactText(reading: NonNullable<SelfAssessmentReading>): string {
    return isOtherCriteria(reading) ? `${displayOf(reading)} ${EARLIER_MARK}` : displayOf(reading);
}

/**
 * The accessible name and tooltip of a reading — the whole story, so that a
 * compact cell or a colour never has to carry it.
 *
 * @param domain the domain it is about; absent for the global answer
 */
export function title(reading: NonNullable<SelfAssessmentReading>, domain?: string): string {
    const subject = domain === undefined ? 'Autoavaliação do aluno' : `Autoavaliação do aluno — ${domain}`;
    const named = namedValue(reading);
    const withScale = reading.scale ? `${named} (${reading.scale})` : named;
    const criteria = reading.criteria ? `: ${reading.criteria}` : '';

    if (reading.earlier === true) {
        const where = reading.criteria ? ` — critérios: ${reading.criteria}` : '';

        return `A nova autoavaliação não tem proposta global. Proposta anterior: ${withScale}${where}`;
    }

    if (reading.previous_criteria === true) {
        return `${subject}: ${withScale} — respondida com critérios anteriores${criteria}`;
    }

    return `${subject}: ${named}`;
}
