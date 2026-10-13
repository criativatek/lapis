import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ConsultationFilters } from './recordFollowup';
import { casesLabel, copyText, filtersToQuery, formatDay, occurrencesLabel, statusLabel, studentLabel, studentsLabel, tileFigures, weekday, withQuery } from './recordFollowup';

function filters(overrides: Partial<ConsultationFilters> = {}): ConsultationFilters {
    return {
        year: 'Y1',
        subject: null,
        class: null,
        student: null,
        kind: null,
        mode: 'period',
        period: 'P1',
        from: null,
        to: null,
        searched: false,
        view: null,
        tab: 'search',
        ...overrides,
    };
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('recordFollowup helpers', () => {
    it('reads a pedagogical day as a calendar day, never shifted by the time zone', () => {
        expect(formatDay('2026-10-03')).toBe('03/10/2026');
        expect(formatDay('2026-12-16 23:30:00')).toBe('16/12/2026');
        expect(weekday('2026-10-12')).toBe('segunda-feira');
        expect(weekday('2026-10-18')).toBe('domingo');
    });

    it('says counts in Portuguese', () => {
        expect(occurrencesLabel(1)).toBe('1 ocorrência');
        expect(occurrencesLabel(14)).toBe('14 ocorrências');
        expect(studentsLabel(1)).toBe('1 aluno');
        expect(studentsLabel(6)).toBe('6 alunos');
        expect(studentLabel(5, 'Ana Costa')).toBe('n.º 5 · Ana Costa');
        expect(studentLabel(null, 'Ana Costa')).toBe('Ana Costa');
        expect(statusLabel('pending')).toBe('Por tratar');
        expect(statusLabel('registered')).toBe('Registado no Inovar');
        expect(statusLabel('none')).toBeNull();
        expect(statusLabel(null)).toBeNull();
    });

    it('changes the current query without losing filters, page or the open case', () => {
        const url = '/records?year=Y1&view=lateness&followup_page=2&case_class=C1&case_student=S1&case_category=lateness';

        expect(withQuery(url, { case_class: null, case_student: null, case_category: null })).toBe('/records?year=Y1&view=lateness&followup_page=2');
        expect(withQuery('/records', { case_class: 'C1' })).toBe('/records?case_class=C1');
    });

    it('sends the period in period mode and the dates in range mode, never both', () => {
        expect(filtersToQuery(filters({ class: 'C1', kind: 'lateness', from: '2026-10-01' }))).toEqual({ year: 'Y1', class: 'C1', kind: 'lateness', period: 'P1' });
        expect(filtersToQuery(filters({ mode: 'range', from: '2026-10-01', to: '2026-10-31' }))).toEqual({ year: 'Y1', mode: 'range', from: '2026-10-01', to: '2026-10-31' });
        expect(filtersToQuery(filters({ period: null }))).toEqual({ year: 'Y1' });
    });

    it('reports a refused clipboard instead of pretending it copied', async () => {
        vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } });
        expect(await copyText('texto')).toBe(false);

        vi.stubGlobal('navigator', {});
        expect(await copyText('texto')).toBe(false);

        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { clipboard: { writeText } });
        expect(await copyText('texto')).toBe(true);
        expect(writeText).toHaveBeenCalledWith('texto');
    });

    it('tells students and cases apart only when they differ', () => {
        expect(casesLabel(1)).toBe('1 caso');
        expect(tileFigures({ students: 6, cases: 6, occurrences: 14 })).toBe('6 alunos · 14 ocorrências');
        expect(tileFigures({ students: 1, cases: 2, occurrences: 5 })).toBe('1 aluno · 2 casos · 5 ocorrências');
    });
});
