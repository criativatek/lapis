<script setup lang="ts">
import { CalendarX2 } from '@lucide/vue';
import { ref } from 'vue';
import LessonOutcomeDialog from '@/components/lessons/LessonOutcomeDialog.vue';
import { Button } from '@/components/ui/button';

export type LessonOutcomeValue = 'taught' | 'teacher_absent' | 'class_external_activity';

/**
 * «Não houve aula» (0.146.0) — registar que a ocorrência fechou como
 * professor ausente ou turma em outras atividades letivas, e mostrar esse
 * resultado depois de registado.
 *
 * O motivo da ausência é SÓ uma categoria: não existe campo de texto para ele.
 * A decisão e as recusas vivem no servidor (RecordLessonOutcome); este
 * componente só as apresenta.
 */
defineProps<{
    lessonUlid: string;
    outcome: LessonOutcomeValue | null;
    outcomeLabel: string | null;
    outcomeReasonLabel: string | null;
    outcomeNote: string | null;
    canRecord: boolean;
    reasons: { value: string; label: string }[];
    pendingPlan: string | null;
}>();

const emit = defineEmits<{ submitting: [boolean] }>();

const dialogOpen = ref(false);
</script>

<template>
    <section
        v-if="outcome !== null && outcome !== 'taught'"
        :class="[
            'space-y-1 rounded-xl border p-4',
            outcome === 'teacher_absent'
                ? 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100'
                : 'border-violet-300 bg-violet-50 text-violet-900 dark:border-violet-900 dark:bg-violet-950 dark:text-violet-100',
        ]"
        data-testid="lesson-outcome"
    >
        <p class="font-semibold">{{ outcomeLabel }}</p>
        <p v-if="outcomeReasonLabel" class="text-sm">Motivo: {{ outcomeReasonLabel }}</p>
        <p v-if="outcomeNote" class="text-sm">{{ outcomeNote }}</p>
        <p class="text-sm">
            <template v-if="outcome === 'teacher_absent'">
                Esta aula não é numerada nem contada como lecionada. A assiduidade não se aplica.
            </template>
            <template v-else>
                Contada como lecionada para o serviço docente, mas não como desenvolvimento da disciplina. A
                assiduidade não se aplica.
            </template>
            O planeamento passou para a aula seguinte.
        </p>
    </section>

    <section
        v-if="pendingPlan"
        class="space-y-2 rounded-xl border p-4"
        data-testid="lesson-pending-plan"
    >
        <h2 class="text-sm font-semibold">Planeamento pendente</h2>
        <p class="text-sm text-muted-foreground">
            Não havia mais nenhuma aula no horário até ao fim do ano letivo para receber este planeamento.
        </p>
        <p class="text-sm whitespace-pre-line">{{ pendingPlan }}</p>
    </section>

    <LessonOutcomeDialog
        v-if="canRecord"
        v-model:open="dialogOpen"
        :lesson-ulid="lessonUlid"
        :reasons="reasons"
        @submitting="(value) => emit('submitting', value)"
    >
        <Button type="button" variant="outline" class="min-h-11" data-testid="open-outcome-dialog" @click="dialogOpen = true">
            <CalendarX2 class="size-4" /> Não houve aula
        </Button>
    </LessonOutcomeDialog>
</template>
