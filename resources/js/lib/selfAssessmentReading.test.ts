import { describe, expect, it } from 'vitest';
import {
    anyOtherCriteria,
    compactText,
    detailText,
    displayOf,
    EARLIER_LEGEND,
    fullText,
    isOtherCriteria,
    namedValue,
    printText,
    title,
} from './selfAssessmentReading';
import type { SelfAssessmentReading } from './selfAssessmentReading';

type Reading = NonNullable<SelfAssessmentReading>;

const CRITERIA = 'Avaliação final · versão 1 · Percentagem (0 a 100)';

const level: Reading = { code: '4', label: 'Bom', display: '4', sequence: 4, is_negative: false, scale: 'Escala 1 a 5', scale_id: 1, previous_criteria: false, criteria: 'Avaliação final · versão 2 · Escala 1 a 5', earlier: false };
const previous: Reading = { code: '70', label: '70%', display: '70%', sequence: null, is_negative: false, scale: 'Percentagem (0 a 100)', scale_id: 3, previous_criteria: true, criteria: CRITERIA, earlier: false };
const earlierOnPreviousCriteria: Reading = { ...previous, earlier: true };
const earlierOnCurrentCriteria: Reading = { ...level, earlier: true };

describe('selfAssessmentReading — the three situations', () => {
    it('writes a reading on the current criteria as its value alone', () => {
        expect(fullText(level)).toBe('4');
        expect(compactText(level)).toBe('4');
        expect(isOtherCriteria(level)).toBe(false);
    });

    it('writes a reading on previous criteria with «critérios anteriores», and «(ant.)» when compact', () => {
        expect(fullText(previous)).toBe('70% · critérios anteriores');
        expect(compactText(previous)).toBe('70% (ant.)');
        expect(isOtherCriteria(previous)).toBe(true);
    });

    it('writes an earlier global as «anterior», with or without the criteria wording', () => {
        expect(fullText(earlierOnPreviousCriteria)).toBe('anterior: 70% · critérios anteriores');
        expect(fullText(earlierOnCurrentCriteria)).toBe('anterior: 4');
        expect(compactText(earlierOnPreviousCriteria)).toBe('70% (ant.)');
        expect(compactText(earlierOnCurrentCriteria)).toBe('4 (ant.)');
        expect(isOtherCriteria(earlierOnCurrentCriteria)).toBe(true);
    });
});

describe('selfAssessmentReading — old saved sheets', () => {
    it('falls back to `code` when `display` is missing', () => {
        const old: Reading = { code: '4', label: 'Bom', sequence: 4, is_negative: false };

        expect(displayOf(old)).toBe('4');
        expect(fullText(old)).toBe('4');
        expect(compactText(old)).toBe('4');
        expect(title(old)).toBe('Autoavaliação do aluno: 4 — Bom');
        expect(isOtherCriteria(old)).toBe(false);
    });

    it('treats null and undefined as having no mark', () => {
        expect(isOtherCriteria(null)).toBe(false);
        expect(isOtherCriteria(undefined)).toBe(false);
    });
});

describe('selfAssessmentReading — the accessible name', () => {
    it('says the value and its mention on the current criteria, with the domain when there is one', () => {
        expect(title(level)).toBe('Autoavaliação do aluno: 4 — Bom');
        expect(title(level, 'Conhecimento')).toBe('Autoavaliação do aluno — Conhecimento: 4 — Bom');
    });

    it('does not repeat a percentage as its own mention', () => {
        expect(namedValue(previous)).toBe('70%');
        expect(detailText({ ...previous, previous_criteria: false })).toBe('70%');
    });

    it('says the criteria a previous answer was given under', () => {
        expect(title(previous)).toBe(`Autoavaliação do aluno: 70% (Percentagem (0 a 100)) — respondida com critérios anteriores: ${CRITERIA}`);
        expect(title(previous, 'Atitudes')).toBe(`Autoavaliação do aluno — Atitudes: 70% (Percentagem (0 a 100)) — respondida com critérios anteriores: ${CRITERIA}`);
    });

    it('says the new self-assessment has no global proposal when the reading is an earlier one', () => {
        expect(title(earlierOnPreviousCriteria)).toBe(`A nova autoavaliação não tem proposta global. Proposta anterior: 70% (Percentagem (0 a 100)) — critérios: ${CRITERIA}`);
    });

    it('leaves out the criteria it was not given', () => {
        expect(title({ ...previous, criteria: null })).toBe('Autoavaliação do aluno: 70% (Percentagem (0 a 100)) — respondida com critérios anteriores');
        expect(title({ ...earlierOnPreviousCriteria, criteria: null, scale: null })).toBe('A nova autoavaliação não tem proposta global. Proposta anterior: 70%');
    });
});

describe('selfAssessmentReading — where there is room', () => {
    it('keeps the mention on the current criteria and the wording otherwise', () => {
        expect(detailText(level)).toBe('4 — Bom');
        expect(detailText(previous)).toBe('70% · critérios anteriores');
        expect(detailText(earlierOnCurrentCriteria)).toBe('anterior: 4');
    });

    it('adds the criteria on paper only when they differ from the current ones', () => {
        expect(printText(level)).toBe('4 — Bom');
        expect(printText(previous)).toBe(`70% · critérios anteriores (${CRITERIA})`);
        expect(printText(earlierOnPreviousCriteria)).toBe(`anterior: 70% · critérios anteriores (${CRITERIA})`);
        expect(printText({ ...previous, criteria: null })).toBe('70% · critérios anteriores');
    });
});

describe('selfAssessmentReading — the legend', () => {
    it('is owed only when some reading carries the mark', () => {
        expect(anyOtherCriteria([level, null, undefined])).toBe(false);
        expect(anyOtherCriteria([level, previous])).toBe(true);
        expect(anyOtherCriteria([null, earlierOnCurrentCriteria])).toBe(true);
        expect(anyOtherCriteria([])).toBe(false);
    });

    it('explains the mark in a sentence that starts with it', () => {
        expect(EARLIER_LEGEND.startsWith('(ant.) — ')).toBe(true);
    });
});
