/**
 * A forma de uma aula na semana, escrita UMA vez.
 *
 * As vistas Semana, Por turma e Horário mostram exatamente as mesmas aulas,
 * construídas no servidor pela mesma linha (WeeklyLessonsQuery e a vista por
 * turma partilham o construtor). Um tipo por vista seria a porta aberta a que
 * uma delas passasse a esperar um campo que a outra não recebe — e a partir daí
 * as vistas deixariam de estar a olhar para a mesma coisa, que é a única regra
 * desta funcionalidade que não se pode quebrar.
 */
export type LessonStatus = 'preparation' | 'prepared' | 'taught';

/** Um acontecimento do Calendário do professor que cobre o dia da aula. */
export type LessonDayEvent = {
    ulid: string;
    title: string;
    starts_at: string | null;
    ends_at: string | null;
    all_day: boolean;
    notes: string | null;
    type_label?: string;
};

export type WeekLesson = {
    ulid: string;
    starts_at: string;
    ends_at: string | null;
    school_class: { ulid: string; label: string; is_support_class: boolean };
    /** «8.º F», ou «8.º F · T1» numa aula de um grupo. Composto no servidor. */
    context_label: string;
    class_group_label: string | null;
    class_group_id: number | null;
    subject: string;
    status: LessonStatus;
    status_label: string;
    has_summary: boolean;
    /** O sumário INTEIRO (0.158.0) — as vistas mostram-no completo por omissão. Null quando vazio. */
    summary: string | null;
    /**
     * A versão do sumário que este ecrã leu (`lessons.summary_version`). Vai com
     * cada gravação, e o servidor recusa-a se entretanto outra janela gravou.
     */
    summary_version: number;
    /** O sumário foi revisto depois de a aula estar lecionada (`reviewed_at`). */
    summary_reviewed: boolean;
    /** O tom guardado desta turma para ESTE professor (`class_teachers.identity_tone`). */
    identity_tone: string | null;
    /** Vazio quando o Calendário não está acessível. */
    day_events: LessonDayEvent[];
    lesson_number: number | null;
    /** Como a ocorrência fechou (0.146.0) — NULL enquanto aberta. */
    outcome: 'taught' | 'teacher_absent' | 'class_external_activity' | null;
    outcome_label: string | null;
    can_delete: boolean;
    can_clear_summary: boolean;
    /** Se a assiduidade desta aula já está consolidada — ver Lesson::attendanceRecorded(). */
    attendance_recorded: boolean;
    /** Null enquanto não está consolidada (o que existe até lá é só rascunho). */
    absent_count: number | null;
};

/**
 * O ÚNICO estado que o cartão de uma aula mostra (0.146.1).
 *
 * Preparação (por preparar / preparado) e resultado da ocorrência (lecionada /
 * professor ausente / turma noutra atividade) são eixos diferentes, mas o
 * cartão mostra um só: uma aula com resultado registado está fechada, e
 * «Preparada» ao lado de «Professor ausente» fazia-a parecer pendente. O
 * resultado ganha; sem resultado, fica a preparação. Escrito uma vez para a
 * Lista, o Horário e a página da aula não voltarem a divergir.
 */
export type LessonStateSource = {
    status: LessonStatus;
    status_label: string;
    outcome: WeekLesson['outcome'];
    outcome_label: string | null;
};

export function lessonDisplayState(lesson: LessonStateSource): { value: string; label: string } {
    // Sem rótulo não se inventa um: cai na preparação. Todas as origens enviam
    // `outcome` e `outcome_label` juntos (`?->label()`); quem acrescentar uma
    // nova tem de manter o par, ou o cartão volta a mostrar a preparação.
    if (lesson.outcome !== null && lesson.outcome !== 'taught' && lesson.outcome_label !== null) {
        return { value: lesson.outcome, label: lesson.outcome_label };
    }

    return { value: lesson.status, label: lesson.status_label };
}

/**
 * Fecho rápido (0.147.0) — em que ponto do tempo está uma aula ABERTA.
 *
 * O sistema sugere; o professor confirma. Nada aqui marca uma aula como
 * lecionada por ter passado a hora: esta função só decide o que o cartão
 * OFERECE, e o servidor continua a aceitar (ou recusar) exatamente o que
 * aceitava antes. É apresentação, não regra de domínio.
 *
 * - `closed`      — já lecionada ou com resultado registado: nada a oferecer.
 * - `future`      — ainda não começou: sem «✓ Lecionada» e fora do lote rápido.
 * - `in_progress` — começou e ainda não terminou: estado normal + «✓ Lecionada».
 * - `ended`       — terminou e continua aberta: «Aula terminada · Confirmar estado».
 *
 * `now` entra como argumento, e não é lido do relógio aqui dentro, para que a
 * mesma aula dê a mesma resposta num teste de outubro e noutro de março.
 * Sem `ends_at`, só se sabe que terminou quando o DIA (em Lisboa) já passou.
 */
export type LessonQuickCloseState = 'closed' | 'future' | 'in_progress' | 'ended';

export type LessonTimingSource = {
    status: LessonStatus;
    outcome: WeekLesson['outcome'];
    starts_at: string;
    ends_at: string | null;
};

const lisbonDateFormatter = new Intl.DateTimeFormat('en-CA', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    timeZone: 'Europe/Lisbon',
});

function lisbonDate(value: Date): string {
    return lisbonDateFormatter.format(value);
}

export function lessonQuickCloseState(lesson: LessonTimingSource, now: Date): LessonQuickCloseState {
    if (lesson.status === 'taught' || lesson.outcome !== null) {
        return 'closed';
    }

    const startsAt = new Date(lesson.starts_at);

    if (startsAt.getTime() > now.getTime()) {
        return 'future';
    }

    if (lesson.ends_at !== null) {
        return new Date(lesson.ends_at).getTime() < now.getTime() ? 'ended' : 'in_progress';
    }

    return lisbonDate(startsAt) < lisbonDate(now) ? 'ended' : 'in_progress';
}

/** Uma aula aberta que já começou — a única que o fecho rápido oferece. */
export function isQuickClosable(lesson: LessonTimingSource, now: Date): boolean {
    const state = lessonQuickCloseState(lesson, now);

    return state === 'in_progress' || state === 'ended';
}

/**
 * O DIA LOCAL de uma aula («2026-09-28»). `starts_at` chega em ISO 8601 com o
 * desvio de Lisboa, e a data à cabeça é por isso já a data local — é a mesma
 * leitura que a lista sempre fez para agrupar por dia.
 */
export function lessonDate(lesson: Pick<WeekLesson, 'starts_at'>): string {
    return lesson.starts_at.slice(0, 10);
}

const lisbonTimeFormatter = new Intl.DateTimeFormat('pt-PT', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Europe/Lisbon',
});

/** «08:20». */
export function lessonClock(iso: string): string {
    return lisbonTimeFormatter.format(new Date(iso));
}

/** «08:20–09:10», ou só «08:20» sem fim conhecido. */
export function lessonTimeRange(
    lesson: Pick<WeekLesson, 'starts_at' | 'ends_at'>,
): string {
    const start = lessonClock(lesson.starts_at);

    return lesson.ends_at ? `${start}–${lessonClock(lesson.ends_at)}` : start;
}

/**
 * O ÂMBITO de uma aula, dito por extenso — a turma inteira deixa de estar
 * implícita na ausência de um sufixo. A cor nunca é o que distingue: cada
 * âmbito tem o seu texto e o seu contorno (contínuo, tracejado, duplo).
 */
export type LessonScopeKind = 'whole' | 'group' | 'support';

export type LessonScope = { kind: LessonScopeKind; label: string };

export function lessonScope(
    lesson: Pick<WeekLesson, 'class_group_label' | 'class_group_id'> & {
        school_class: { is_support_class: boolean };
    },
): LessonScope {
    if (lesson.class_group_id !== null && lesson.class_group_label) {
        return { kind: 'group', label: `Grupo ${lesson.class_group_label}` };
    }

    if (lesson.school_class.is_support_class) {
        return { kind: 'support', label: 'Turma de apoio' };
    }

    return { kind: 'whole', label: 'Turma inteira' };
}

function overlaps(
    a: Pick<WeekLesson, 'starts_at' | 'ends_at'>,
    b: Pick<WeekLesson, 'starts_at' | 'ends_at'>,
): boolean {
    const aStart = new Date(a.starts_at).getTime();
    const bStart = new Date(b.starts_at).getTime();
    const aEnd = a.ends_at ? new Date(a.ends_at).getTime() : aStart;
    const bEnd = b.ends_at ? new Date(b.ends_at).getTime() : bStart;

    return aStart < bEnd && bStart < aEnd;
}

/**
 * As outras aulas da lista que se cruzam com esta no mesmo dia. T1 e T2 da
 * mesma turma podem ter aula à mesma hora (LessonConflicts: grupo A × grupo B
 * não colide), e turmas diferentes também — é a agenda do professor.
 */
export function simultaneousLessons(
    lesson: WeekLesson,
    lessons: readonly WeekLesson[],
): WeekLesson[] {
    return lessons.filter(
        (other) =>
            other.ulid !== lesson.ulid &&
            lessonDate(other) === lessonDate(lesson) &&
            overlaps(lesson, other),
    );
}

/**
 * «Tempo seguido»: a aula imediatamente anterior na lista é da mesma turma e do
 * mesmo grupo e termina exatamente quando esta começa. Cada tempo continua a
 * ter o seu número e o seu sumário — isto só o diz, não junta nada.
 */
export function followsPreviousWithoutBreak(
    lesson: WeekLesson,
    lessons: readonly WeekLesson[],
): boolean {
    const index = lessons.findIndex((other) => other.ulid === lesson.ulid);
    const previous = index > 0 ? lessons[index - 1] : undefined;

    return (
        previous !== undefined &&
        previous.ends_at !== null &&
        lessonDate(previous) === lessonDate(lesson) &&
        previous.school_class.ulid === lesson.school_class.ulid &&
        previous.class_group_id === lesson.class_group_id &&
        new Date(previous.ends_at).getTime() ===
            new Date(lesson.starts_at).getTime()
    );
}

/** «Lecionada», nos termos de Lesson::isTaught(). */
export function isTaughtLesson(
    lesson: Pick<WeekLesson, 'status' | 'outcome'>,
): boolean {
    return (
        lesson.outcome === 'taught' ||
        (lesson.outcome === null && lesson.status === 'taught')
    );
}

/**
 * Porque é que uma aula está «Sem sumário», dito ao lado da falta — para que
 * «Tem sumário», «Preparada» e «Lecionada» nunca se confundam: o estado está na
 * pílula, a presença do sumário vê-se no texto, e a falta diz o seu motivo.
 */
export function emptySummaryReason(
    lesson: WeekLesson,
    now: Date,
): string | null {
    if (lesson.outcome === 'teacher_absent') {
        return 'O planeamento passou para a aula seguinte.';
    }

    if (isTaughtLesson(lesson)) {
        return 'A aula já foi lecionada e ainda não tem registo do que foi dado.';
    }

    if (lessonQuickCloseState(lesson, now) === 'future') {
        return 'Escrever o sumário prepara a aula.';
    }

    return null;
}

/**
 * Parágrafos de um sumário: uma linha em branco separa parágrafos; uma quebra
 * simples fica dentro do parágrafo (mostrada com `whitespace-pre-line`).
 */
export function summaryParagraphs(text: string): string[] {
    return text
        .replace(/\r\n/g, '\n')
        .split(/\n\s*\n/)
        .map((paragraph) => paragraph.trim())
        .filter((paragraph) => paragraph !== '');
}

/** O sumário numa linha só, para o modo compacto e o Horário. */
export function summaryOneLine(text: string): string {
    return text
        .trim()
        .replace(/\s*\n\s*\n\s*/g, ' · ')
        .replace(/\s*\n\s*/g, ' · ');
}
