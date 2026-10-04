import { lessonClock, lessonDate } from '@/lib/lessons';
import type { WeekLesson } from '@/lib/lessons';

/**
 * AS LINHAS DO HORÁRIO SAEM DAS HORAS REAIS DAS AULAS (0.158.0).
 *
 * Cada início e cada fim de aula da semana é uma fronteira; as linhas são os
 * troços entre fronteiras consecutivas. Continua a não se inventar a grelha da
 * escola — não há régua de horas fixas, há as horas que as aulas têm —, mas
 * passa a ler-se o tempo: a mesma hora fica na mesma linha em todos os dias, e
 * um troço sem aula em nenhum dia é um tempo livre, dito como tal.
 *
 * Aulas que se cruzam no mesmo dia (T1 e T2 à mesma hora, ou duas turmas)
 * ficam em PISTAS lado a lado, sem se taparem.
 *
 * Funções puras, sem DOM, para que a forma do horário se teste sozinha.
 */

/** Minutos desde a meia-noite, em Lisboa. */
export function minutesOfDay(iso: string): number {
    const [hours, minutes] = lessonClock(iso).split(':').map(Number);

    return (hours ?? 0) * 60 + (minutes ?? 0);
}

export function formatMinutes(total: number): string {
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

function startOf(lesson: WeekLesson): number {
    return minutesOfDay(lesson.starts_at);
}

/** Sem fim conhecido, a aula ocupa um troço simbólico de um minuto. */
function endOf(lesson: WeekLesson): number {
    return lesson.ends_at
        ? Math.max(minutesOfDay(lesson.ends_at), startOf(lesson) + 1)
        : startOf(lesson) + 1;
}

export type TimetableSegment = {
    from: number;
    to: number;
    /** Há pelo menos uma aula, em algum dia, neste troço. */
    busy: boolean;
};

/** Os troços da semana, do primeiro início ao último fim. */
export function timetableSegments(
    lessons: readonly WeekLesson[],
): TimetableSegment[] {
    const bounds = [
        ...new Set(
            lessons.flatMap((lesson) => [startOf(lesson), endOf(lesson)]),
        ),
    ].sort((a, b) => a - b);
    const segments: TimetableSegment[] = [];

    for (let index = 0; index < bounds.length - 1; index++) {
        const from = bounds[index] as number;
        const to = bounds[index + 1] as number;
        segments.push({
            from,
            to,
            busy: lessons.some(
                (lesson) => startOf(lesson) < to && endOf(lesson) > from,
            ),
        });
    }

    return segments;
}

export type DayLanes = {
    /** Número de pistas do dia (≥ 1). */
    count: number;
    laneOf: Map<string, number>;
};

/** Pistas de um dia: cada aula vai para a primeira pista livre à sua hora. */
export function dayLanes(dayLessons: readonly WeekLesson[]): DayLanes {
    const ends: number[] = [];
    const laneOf = new Map<string, number>();

    [...dayLessons]
        .sort((a, b) => startOf(a) - startOf(b) || endOf(a) - endOf(b))
        .forEach((lesson) => {
            let lane = ends.findIndex((end) => end <= startOf(lesson));

            if (lane < 0) {
                lane = ends.length;
                ends.push(0);
            }

            ends[lane] = endOf(lesson);
            laneOf.set(lesson.ulid, lane);
        });

    return { count: Math.max(1, ends.length), laneOf };
}

/** A linha da grelha (1-based, depois do cabeçalho) onde a aula começa e acaba. */
export function lessonRowSpan(
    lesson: WeekLesson,
    segments: readonly TimetableSegment[],
): { start: number; end: number } {
    const startIndex = segments.findIndex(
        (segment) => segment.from === startOf(lesson),
    );
    const endIndex = segments.findIndex(
        (segment) => segment.to === endOf(lesson),
    );

    return { start: startIndex + 2, end: endIndex + 3 };
}

/**
 * A altura mínima de cada linha: proporcional à duração nos troços com aulas;
 * baixa nos tempos livres (o almoço fica mais alto do que um intervalo de cinco
 * minutos, sem roubar o ecrã).
 */
export function segmentRowSize(segment: TimetableSegment): string {
    const minutes = segment.to - segment.from;

    return segment.busy
        ? `minmax(${Math.round(minutes * 1.6)}px, auto)`
        : `${Math.max(20, Math.min(44, Math.round(minutes * 0.7)))}px`;
}

export type DayCluster =
    | { kind: 'lesson'; lessons: [WeekLesson]; from: number; to: number }
    | { kind: 'simultaneous'; lessons: WeekLesson[]; from: number; to: number }
    | { kind: 'free'; lessons: []; from: number; to: number };

/**
 * O DIA NO TELEMÓVEL: as aulas por ordem, as que se cruzam agrupadas sob um
 * cabeçalho horário comum (cada aula e cada grupo continuam separados), e os
 * tempos livres entre elas.
 */
export function dayClusters(dayLessons: readonly WeekLesson[]): DayCluster[] {
    const sorted = [...dayLessons].sort(
        (a, b) => startOf(a) - startOf(b) || endOf(a) - endOf(b),
    );
    const groups: { lessons: WeekLesson[]; from: number; to: number }[] = [];

    for (const lesson of sorted) {
        const current = groups[groups.length - 1];

        if (current && startOf(lesson) < current.to) {
            current.lessons.push(lesson);
            current.to = Math.max(current.to, endOf(lesson));
        } else {
            groups.push({
                lessons: [lesson],
                from: startOf(lesson),
                to: endOf(lesson),
            });
        }
    }

    const clusters: DayCluster[] = [];

    groups.forEach((group, index) => {
        const previous = groups[index - 1];

        if (previous && group.from > previous.to) {
            clusters.push({
                kind: 'free',
                lessons: [],
                from: previous.to,
                to: group.from,
            });
        }

        clusters.push(
            group.lessons.length === 1
                ? {
                      kind: 'lesson',
                      lessons: [group.lessons[0] as WeekLesson],
                      from: group.from,
                      to: group.to,
                  }
                : {
                      kind: 'simultaneous',
                      lessons: group.lessons,
                      from: group.from,
                      to: group.to,
                  },
        );
    });

    return clusters;
}

/** Os dias da semana apresentada: segunda a sexta sempre; sábado e domingo só com aulas. */
export function timetableDays(
    weekStart: string,
    lessons: readonly WeekLesson[],
): string[] {
    const start = new Date(`${weekStart}T12:00:00Z`);
    const days: string[] = [];

    for (let offset = 0; offset < 7; offset++) {
        const date = new Date(start);
        date.setUTCDate(date.getUTCDate() + offset);
        const key = date.toISOString().slice(0, 10);

        if (
            offset >= 5 &&
            !lessons.some((lesson) => lessonDate(lesson) === key)
        ) {
            continue;
        }

        days.push(key);
    }

    return days;
}

export const FREE_TIME_LABEL = 'Sem aulas no horário do professor';
