import { describe, expect, it } from 'vitest';
import { baseOnLabel, contextDayMonth, contextHistoryUrl, contextStateLabel } from '@/lib/lessonContext';

describe('lessonContext', () => {
    it('reads the local calendar day from the ISO string', () => {
        expect(contextDayMonth('2026-10-07T00:30:00+01:00')).toBe('07/10');
    });

    it('labels the base lesson with its date and state', () => {
        expect(
            baseOnLabel({
                content: 'x',
                private_notes: null,
                resources: null,
                homework: null,
                starts_at: '2026-10-07T09:00:00+01:00',
                state: 'prepared',
                state_label: 'Preparada — por lecionar',
            }),
        ).toBe('Basear na aula de 07/10 (Preparada — por lecionar)');
        expect(baseOnLabel(null)).toBe('Basear no sumário anterior');
    });

    it('falls back to a local state label', () => {
        expect(contextStateLabel('taught')).toBe('Lecionada');
        expect(contextStateLabel('prepared')).toBe('Preparada — por lecionar');
    });

    it('builds the history link on the class view, in the week of the previous lesson', () => {
        expect(contextHistoryUrl({ class_ulid: '01CLASS', starts_at: '2026-10-05T09:00:00+01:00' })).toBe(
            '/lessons?view=turma&class=01CLASS&week=2026-10-05',
        );
    });
});
