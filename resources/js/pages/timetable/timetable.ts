/**
 * O sinal de turma do Horário do Professor vive agora em `@/lib/turmaTones`,
 * partilhado com Aulas e Sumários: a mesma turma tem o mesmo tom nas duas
 * páginas, e o tom é o guardado em `class_teachers.identity_tone` (0.158.0).
 * Este módulo só o reexporta, para quem já o importava daqui.
 */
export type { TurmaTone } from '@/lib/turmaTones';
export {
    assignTurmaTones,
    resolveTurmaTones,
    TURMA_BADGE,
    TURMA_BAR,
    TURMA_TONES,
    turmaBadgeClass,
    turmaBarClass,
} from '@/lib/turmaTones';
