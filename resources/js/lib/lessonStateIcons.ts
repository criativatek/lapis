import {
    CircleCheck,
    CircleDashed,
    ClipboardCheck,
    Route,
    UserX,
} from '@lucide/vue';
import type { Component } from 'vue';

/** O ícone de cada estado de aula (`lessonDisplayState().value`), escrito uma vez. */
export const LESSON_STATE_ICONS: Record<string, Component> = {
    preparation: CircleDashed,
    prepared: ClipboardCheck,
    taught: CircleCheck,
    teacher_absent: UserX,
    class_external_activity: Route,
};

export function lessonStateIcon(state: string): Component {
    return LESSON_STATE_ICONS[state] ?? CircleDashed;
}
