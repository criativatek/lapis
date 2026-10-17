<script setup lang="ts">
import { computed } from 'vue';
import ActivitySessionStatePill from '@/components/activity-sessions/ActivitySessionStatePill.vue';
import { activitySessionNumberLabel } from '@/lib/activitySessions';
import type { ActivitySessionItem } from '@/lib/activitySessions';
import type { TimetableActivity, TimetableSlot } from './types';

/**
 * UM BLOCO DA SEMANA: uma aula, uma «outra atividade» ou uma sessão que já não
 * bate com nenhum bloco. Clicar abre o diálogo certo — quem decide o que se
 * abre é a página: a aula abre a correção do horário, a atividade abre o
 * registo da sessão dessa data (0.170.0).
 *
 * A aula leva o tom da sua turma (barra na margem, cápsula à volta do nome); a
 * atividade é deliberadamente neutra e tracejada, para nunca se confundir com
 * uma aula, e mostra o estado da sessão (Preparada, Realizada, Não realizada).
 */
const props = defineProps<{
    lessonSlot?: TimetableSlot;
    activity?: TimetableActivity;
    /** Uma sessão com registos que ficou fora do horário atual. */
    session?: ActivitySessionItem;
    barClass?: string;
    badgeClass?: string;
}>();

const occurrence = computed<ActivitySessionItem | null>(
    () => props.session ?? props.activity?.occurrence ?? null,
);
// «Por realizar» sem texto é o estado de qualquer ocorrência: não se desenha.
const showState = computed(
    () =>
        occurrence.value !== null &&
        occurrence.value.presentation !== 'planned',
);
const numberLabel = computed(() =>
    occurrence.value ? activitySessionNumberLabel(occurrence.value) : null,
);

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
        <span class="text-sm font-medium wrap-anywhere">{{
            activity.title
        }}</span>
        <span class="text-xs text-muted-foreground"
            >{{ activity.kind_label
            }}<template v-if="activity.school_class">
                · {{ activity.school_class.label }}</template
            ></span
        >
        <span v-if="validity()" class="text-xs text-muted-foreground">{{
            validity()
        }}</span>
        <span
            v-if="showState && occurrence"
            class="flex flex-wrap items-center gap-1.5"
        >
            <ActivitySessionStatePill
                :presentation="occurrence.presentation"
                :label="occurrence.presentation_label"
                size="sm"
            />
            <span v-if="numberLabel" class="text-xs text-muted-foreground">{{
                numberLabel
            }}</span>
        </span>
    </button>
    <button
        v-else-if="session"
        type="button"
        data-activity-block
        data-detached-session
        class="flex w-full flex-col gap-1 border-l-2 border-dashed border-muted-foreground/40 bg-muted/20 p-3 text-left transition-colors outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring"
        @click="$emit('select')"
    >
        <time class="text-sm font-medium tabular-nums"
            >{{ session.start_time }}–{{ session.end_time }}</time
        >
        <span class="text-sm font-medium wrap-anywhere">{{
            session.title
        }}</span>
        <span class="text-xs text-muted-foreground"
            >{{ session.kind_label
            }}<template v-if="session.school_class">
                · {{ session.school_class.label }}</template
            ></span
        >
        <span class="flex flex-wrap items-center gap-1.5">
            <ActivitySessionStatePill
                :presentation="session.presentation"
                :label="session.presentation_label"
                size="sm"
            />
            <span v-if="numberLabel" class="text-xs text-muted-foreground">{{
                numberLabel
            }}</span>
        </span>
    </button>
</template>
