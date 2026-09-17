/**
 * A forma de uma aula na semana, escrita UMA vez.
 *
 * A Vista Lista e a Vista Horário mostram exatamente as mesmas aulas, do mesmo
 * `WeeklyLessonsQuery`. Um tipo por vista seria a porta aberta a que uma delas
 * passasse a esperar um campo que a outra não recebe — e a partir daí as duas
 * vistas deixariam de estar a olhar para a mesma coisa, que é a única regra
 * desta funcionalidade que não se pode quebrar.
 */
export type LessonStatus = 'preparation' | 'prepared' | 'taught';

export type WeekLesson = {
    ulid: string;
    starts_at: string;
    ends_at: string | null;
    school_class: { ulid: string; label: string };
    /** «8.º F», ou «8.º F · T1» numa aula de um grupo. Composto no servidor. */
    context_label: string;
    class_group_label: string | null;
    class_group_id: number | null;
    subject: string;
    status: LessonStatus;
    status_label: string;
    has_summary: boolean;
    summary_excerpt: string | null;
    /** Só vem preenchido quando é MAIOR do que o excerto — ver WeeklyLessonsQuery. */
    summary_full: string | null;
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
