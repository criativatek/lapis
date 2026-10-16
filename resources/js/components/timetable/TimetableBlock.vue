<script setup lang="ts">
import type { TimetableActivity, TimetableSlot } from './types';

/**
 * UM BLOCO DA SEMANA, aula ou atividade não letiva. Clicar abre o diálogo de
 * correção — quem decide o que se abre é a página.
 *
 * A aula leva o tom da sua turma (barra na margem, cápsula à volta do nome); a
 * atividade não letiva é deliberadamente neutra e tracejada, para nunca se
 * confundir com uma aula.
 */
const props = defineProps<{
    lessonSlot?: TimetableSlot;
    activity?: TimetableActivity;
    barClass?: string;
    badgeClass?: string;
}>();

defineEmits<{ select: [] }>();

function validity(): string | null {
    const source = props.lessonSlot ?? props.activity;

    if (!source || (!source.starts_on && !source.ends_on)) {
        return null;
    }

    return `Vigência: ${source.starts_on ?? 'início do ano'} a ${source.ends_on ?? 'fim do ano'}`;
}
</script>

<template>
    <button
        v-if="lessonSlot"
        type="button"
        class="flex w-full flex-col gap-1 p-3 text-left transition-colors outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring"
        :class="barClass"
        @click="$emit('select')"
    >
        <time class="text-sm font-medium tabular-nums"
            >{{ lessonSlot.starts_at }}–{{ lessonSlot.ends_at }}</time
        >
        <span class="text-sm font-medium" :class="badgeClass">{{
            lessonSlot.school_class.label
        }}</span>
        <span class="text-sm text-muted-foreground">{{
            lessonSlot.subject
        }}</span>
        <span v-if="validity()" class="text-xs text-muted-foreground">{{
            validity()
        }}</span>
    </button>
    <button
        v-else-if="activity"
        type="button"
        data-activity-block
        class="flex w-full flex-col gap-1 border-l-2 border-dashed border-muted-foreground/40 bg-muted/20 p-3 text-left transition-colors outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring"
        @click="$emit('select')"
    >
        <time class="text-sm font-medium tabular-nums"
            >{{ activity.starts_at }}–{{ activity.ends_at }}</time
        >
        <span class="text-sm font-medium">{{ activity.title }}</span>
        <span class="text-xs text-muted-foreground"
            >{{ activity.kind_label
            }}<template v-if="activity.school_class">
                · {{ activity.school_class.label }}</template
            ></span
        >
        <span v-if="validity()" class="text-xs text-muted-foreground">{{
            validity()
        }}</span>
    </button>
</template>
