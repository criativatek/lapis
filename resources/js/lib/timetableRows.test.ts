import { describe, expect, it } from 'vitest';
import type { WeekLesson } from '@/lib/lessons';
import { dayClusters, dayLanes, formatMinutes, lessonRowSpan, segmentRowSize, timetableDays, timetableSegments } from '@/lib/timetableRows';

function lesson(ulid: string, date: string, start: string, end: string | null): WeekLesson {
    return {
        ulid,
        starts_at: `${date}T${start}:00+01:00`,
        ends_at: end === null ? null : `${date}T${end}:00+01:00`,
    } as WeekLesson;
}

describe('timetableSegments — as linhas saem das horas reais', () => {
    it('cada início e fim é uma fronteira; um troço sem aula em nenhum dia é tempo livre', () => {
        const segments = timetableSegments([
            lesson('a', '2026-09-28', '08:20', '09:10'),
            lesson('b', '2026-09-29', '10:20', '11:10'),
            lesson('c', '2026-09-30', '14:00', '14:50'),
        ]);

        expect(segments.map((segment) => [formatMinutes(segment.from), formatMinutes(segment.to), segment.busy])).toEqual([
            ['08:20', '09:10', true],
            ['09:10', '10:20', false],
            ['10:20', '11:10', true],
            ['11:10', '14:00', false],
            ['14:00', '14:50', true],
        ]);
    });

    it('a mesma hora em dias diferentes fica na mesma linha', () => {
        const segments = timetableSegments([lesson('a', '2026-09-28', '08:20', '09:10'), lesson('b', '2026-10-01', '08:20', '09:10')]);

        expect(segments).toHaveLength(1);
    });

    it('um cruzamento parcial dá três troços', () => {
        const lessons = [lesson('a', '2026-09-28', '14:10', '15:00'), lesson('b', '2026-09-28', '14:30', '15:20')];
        const segments = timetableSegments(lessons);

        expect(segments.map((segment) => formatMinutes(segment.from))).toEqual(['14:10', '14:30', '15:00']);
        expect(lessonRowSpan(lessons[0], segments)).toEqual({ start: 2, end: 4 });
        expect(lessonRowSpan(lessons[1], segments)).toEqual({ start: 3, end: 5 });
    });

    it('uma aula sem hora de fim ocupa um troço simbólico, sem partir a grelha', () => {
        expect(timetableSegments([lesson('a', '2026-09-28', '08:20', null)])).toHaveLength(1);
    });

    it('os tempos livres são baixos; os troços com aulas são proporcionais', () => {
        expect(segmentRowSize({ from: 0, to: 5, busy: false })).toBe('20px');
        expect(segmentRowSize({ from: 0, to: 60, busy: false })).toBe('42px');
        expect(segmentRowSize({ from: 0, to: 50, busy: true })).toBe('minmax(80px, auto)');
    });
});

describe('dayLanes — aulas simultâneas em pistas', () => {
    it('duas aulas à mesma hora ocupam duas pistas; uma depois volta à primeira', () => {
        const lanes = dayLanes([
            lesson('t1', '2026-10-01', '08:20', '09:10'),
            lesson('t2', '2026-10-01', '08:20', '09:10'),
            lesson('later', '2026-10-01', '10:20', '11:10'),
        ]);

        expect(lanes.count).toBe(2);
        expect([lanes.laneOf.get('t1'), lanes.laneOf.get('t2'), lanes.laneOf.get('later')]).toEqual([0, 1, 0]);
    });

    it('um dia sem aulas tem uma pista', () => {
        expect(dayLanes([]).count).toBe(1);
    });
});

describe('dayClusters — o dia no telemóvel', () => {
    it('agrupa as simultâneas sob um horário comum e diz os tempos livres entre elas', () => {
        const clusters = dayClusters([
            lesson('t1', '2026-10-01', '08:20', '09:10'),
            lesson('t2', '2026-10-01', '08:20', '09:10'),
            lesson('a', '2026-10-01', '10:20', '11:10'),
        ]);

        expect(clusters.map((cluster) => [cluster.kind, formatMinutes(cluster.from), formatMinutes(cluster.to), cluster.lessons.length])).toEqual([
            ['simultaneous', '08:20', '09:10', 2],
            ['free', '09:10', '10:20', 0],
            ['lesson', '10:20', '11:10', 1],
        ]);
    });

    it('aulas seguidas não criam tempo livre entre elas', () => {
        const clusters = dayClusters([lesson('a', '2026-10-01', '08:20', '09:10'), lesson('b', '2026-10-01', '09:10', '10:00')]);

        expect(clusters.map((cluster) => cluster.kind)).toEqual(['lesson', 'lesson']);
    });
});

describe('timetableDays', () => {
    it('segunda a sexta sempre; sábado e domingo só com aulas', () => {
        expect(timetableDays('2026-09-28', [])).toEqual(['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02']);
        expect(timetableDays('2026-09-28', [lesson('s', '2026-10-03', '09:00', '10:00')])).toHaveLength(6);
    });
});
