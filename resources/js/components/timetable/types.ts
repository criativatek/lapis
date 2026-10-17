import type { ActivitySessionItem } from '@/lib/activitySessions';

/**
 * Os tipos que o Horário do Professor e os seus diálogos partilham. Vêm do
 * servidor tal e qual (TeacherTimetableController::index).
 */
export type TimetableSlot = {
    ulid: string;
    day_of_week: number;
    starts_at: string;
    ends_at: string;
    occurs_on?: string;
    starts_on: string | null;
    ends_on: string | null;
    already_in_vigor?: boolean;
    requires_versioning?: boolean;
    class_group_id?: number | null;
    class_group_label?: string | null;
    /** `id`: o corpo dos pedidos a `lesson-slots` leva o id da turma. */
    school_class: {
        id?: number;
        ulid: string;
        label: string;
        identity_tone?: string | null;
    };
    subject: string;
};

export type TimetableClassOption = {
    id?: number;
    ulid: string;
    label: string;
    subject: string;
    identity_tone?: string | null;
};

export type TimetableActivity = {
    ulid: string;
    kind: string;
    kind_label: string;
    title: string;
    day_of_week: number;
    starts_at: string;
    ends_at: string;
    starts_on: string | null;
    ends_on: string | null;
    school_class: { ulid: string; label: string } | null;
    /** Numeração opcional das sessões (0.170.0). */
    numbering_enabled?: boolean;
    numbering_start?: number;
    /** Sessões com registos: se houver, eliminar o bloco fecha-o em vez de o apagar. */
    records_count?: number;
    /** A ocorrência do bloco na semana mostrada, com o estado da sessão dessa data. */
    occurrence?: ActivitySessionItem | null;
};

export type TimetableActivityKindOption = { value: string; label: string };

export const WEEKDAYS = [
    'segunda-feira',
    'terça-feira',
    'quarta-feira',
    'quinta-feira',
    'sexta-feira',
    'sábado',
    'domingo',
];
