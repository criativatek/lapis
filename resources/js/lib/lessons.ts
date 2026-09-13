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
    can_delete: boolean;
    can_clear_summary: boolean;
};
