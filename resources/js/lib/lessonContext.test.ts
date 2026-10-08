import { describe, expect, it } from 'vitest';
import {
    baseOnLabel,
    contextDayMonth,
    contextStateLabel,
    firstLine,
    isLongContextText,
    nextContextLimit,
    sortContextChronologically,
} from '@/lib/lessonContext';
import type { LessonContextEntry } from '@/lib/lessonContext';

function entry(ulid: string, startsAt: string): LessonContextEntry {
    return {
        ulid,
        starts_at: startsAt,
        ends_at: null,
        lesson_number: null,
        context_label: '8.º F',
        state: 'taught',
        state_label: 'Lecionada',
        content: '',
        resources: null,
        homework: null,
    };
}

describe('lessonContext', () => {
    it('orders entries chronologically without mutating the input', () => {
        const input = [
            entry('b', '2026-10-06T09:00:00+01:00'),
            entry('c', '2026-10-06T14:00:00+01:00'),
            entry('a', '2026-10-05T09:00:00+01:00'),
        ];

        expect(sortContextChronologically(input).map((item) => item.ulid)).toEqual(['a', 'b', 'c']);
        expect(input.map((item) => item.ulid)).toEqual(['b', 'c', 'a']);
    });

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

    it('takes the first non-empty line', () => {
        expect(firstLine('\n  \n  Funções afins.\nSegunda linha')).toBe('Funções afins.');
        expect(firstLine('   ')).toBe('');
    });

    it('detects long texts and caps the page size at 20', () => {
        expect(isLongContextText('curto')).toBe(false);
        expect(isLongContextText('x'.repeat(400))).toBe(true);
        expect(isLongContextText('1\n2\n3\n4\n5\n6')).toBe(true);
        expect(nextContextLimit(5)).toBe(10);
        expect(nextContextLimit(20)).toBe(20);
    });
});
