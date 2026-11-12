<script setup lang="ts">
import { CalendarX2, PencilLine, Undo2 } from '@lucide/vue';
import { ref } from 'vue';
import LessonOutcomeDialog from '@/components/lessons/LessonOutcomeDialog.vue';
import { Button } from '@/components/ui/button';
import type { AnnulTaught, NotHeldOptions, OutcomeRectification } from '@/lib/lessonOutcomeOptions';
import { statusToneClasses } from '@/lib/statusTone';

export type LessonOutcomeValue = 'taught' | 'teacher_absent' | 'class_external_activity' | 'not_held';

/**
 * «Não houve aula» (0.146.0) — registar que a ocorrência fechou como
 * professor ausente ou turma em outras atividades letivas, e mostrar esse
 * resultado depois de registado.
 *
 * O motivo da ausência é SÓ uma categoria: não existe campo de texto para ele.
 * A decisão e as recusas vivem no servidor (RecordLessonOutcome); este
 * componente só as apresenta.
 *
 * A cor vem da paleta da casa (`statusToneClasses`): ausência âmbar, outras
 * atividades violeta — as mesmas da lista de aulas e do resto da app, em vez
 * de uma segunda cópia escrita à mão aqui (#21).
 */
defineProps<{
    lessonUlid: string;
    outcome: LessonOutcomeValue | null;
    outcomeLabel: string | null;
    outcomeReasonLabel: string | null;
    /** A categoria existe mas é de outro professor: só se diz que está reservada. */
    outcomeReasonReserved?: boolean;
    outcomeNote: string | null;
    /** «Aula não realizada»: a causa é da turma; a especificação e a descrição só chegam a quem registou. */
    outcomeCauseLabel?: string | null;
    outcomeTeacherSituationLabel?: string | null;
    outcomeCauseNote?: string | null;
    notHeldOptions?: NotHeldOptions | null;
    /** A aula é anterior a aulas já fechadas: o registo retroativo, com as razões que o impedem. */
    retroactiveOutcome?: { closed_after: string; blockers: string[] } | null;
    /** Corrigir o resultado já registado: só para o titular do registo (o servidor decide); inclui o histórico. */
    outcomeRectification?: OutcomeRectification | null;
    /** Anular um «Lecionada» (0.194.0): só chega a quem pode; o destino do sumário vem do servidor. */
    annulTaught?: AnnulTaught | null;
    /** «8.º F · 09:30–10:20» — identifica a aula no diálogo. */
    lessonContext?: string | null;
    canRecord: boolean;
    reasons: { value: string; label: string }[];
    pendingPlan: string | null;
    /** Há texto por guardar no sumário desta aula (0.171.0): o diálogo pede para guardar primeiro. */
    unsavedChanges?: boolean;
    /** A gravação da página, para o «Guardar alterações» do diálogo — ver LessonOutcomeDialog. */
    saveFirst?: () => Promise<boolean>;
}>();

const emit = defineEmits<{ submitting: [boolean] }>();

const dialogOpen = ref(false);
const retroactiveOpen = ref(false);
const rectifyOpen = ref(false);
const annulOpen = ref(false);

const when = (iso: string): string =>
    new Date(iso).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' });
</script>

<template>
    <section
        v-if="outcome !== null && outcome !== 'taught'"
        :class="['space-y-1 rounded-xl border p-4', statusToneClasses(outcome)]"
        data-testid="lesson-outcome"
    >
        <p class="font-semibold">{{ outcomeLabel }}</p>
        <p v-if="outcomeReasonLabel" class="text-sm">Motivo: {{ outcomeReasonLabel }}</p>
        <p v-else-if="outcomeReasonReserved" class="text-sm" data-testid="lesson-outcome-reason-reserved">
            O motivo está reservado a quem registou este resultado.
        </p>
        <p v-if="outcomeCauseLabel" class="text-sm" data-testid="lesson-outcome-cause">Causa: {{ outcomeCauseLabel }}</p>
        <p v-if="outcomeTeacherSituationLabel" class="text-sm" data-testid="lesson-outcome-situation">
            A tua situação: {{ outcomeTeacherSituationLabel }}
        </p>
        <p v-if="outcomeCauseNote" class="text-sm">{{ outcomeCauseNote }}</p>
        <p v-if="outcomeNote" class="text-sm">{{ outcomeNote }}</p>
        <p class="text-sm">
            <template v-if="outcome === 'teacher_absent' || outcome === 'not_held'">
                Esta aula não é numerada nem contada como lecionada. A assiduidade não se aplica.
            </template>
            <template v-else>
                Contada como lecionada para o serviço docente, mas não como desenvolvimento da disciplina. A
                assiduidade não se aplica.
            </template>
            O planeamento passou para a aula seguinte.
        </p>

        <div v-if="outcomeRectification" class="space-y-2 pt-2" data-testid="lesson-outcome-rectification">
            <LessonOutcomeDialog
                v-model:open="rectifyOpen"
                :lesson-ulid="lessonUlid"
                :reasons="reasons"
                :not-held-options="notHeldOptions ?? null"
                :rectify="outcomeRectification"
                :lesson-context="lessonContext ?? null"
            >
                <Button type="button" variant="outline" class="min-h-11" data-testid="open-rectify-dialog" @click="rectifyOpen = true">
                    <PencilLine class="size-4" /> Corrigir resultado
                </Button>
            </LessonOutcomeDialog>

            <details v-if="outcomeRectification.history.length > 0" class="text-sm" data-testid="rectification-history">
                <summary class="min-h-11 cursor-pointer py-3">
                    Correções ({{ outcomeRectification.history.length }})
                </summary>
                <ul class="space-y-3">
                    <li v-for="entry in outcomeRectification.history" :key="entry.ulid" data-testid="rectification-entry">
                        <p class="font-medium">{{ when(entry.at) }}<span v-if="entry.by"> · {{ entry.by }}</span><span v-if="entry.by_direction"> · pela direção</span></p>
                        <p v-for="change in entry.changes" :key="change.field">
                            {{ change.label }}: {{ change.from }} → {{ change.to }}
                        </p>
                        <p class="text-muted-foreground">Motivo: {{ entry.motive }}</p>
                    </li>
                </ul>
            </details>
        </div>

        <!-- A aula não se realizou e foi fechada por engano: a única porta para
             desfazer um «Lecionada». O servidor diz se se pode e o que acontece
             ao sumário; o diálogo diz-o ao professor antes de ele confirmar. -->
        <div v-if="annulTaught && notHeldOptions" class="pt-2" data-testid="lesson-annul-taught">
            <LessonOutcomeDialog
                v-model:open="annulOpen"
                :lesson-ulid="lessonUlid"
                :reasons="reasons"
                :not-held-options="notHeldOptions"
                :annul="annulTaught"
                :lesson-context="lessonContext ?? null"
                @submitting="emit('submitting', $event)"
            >
                <Button type="button" variant="outline" class="min-h-11" data-testid="open-annul-dialog" @click="annulOpen = true">
                    <Undo2 class="size-4" /> Corrigir: a aula não se realizou
                </Button>
            </LessonOutcomeDialog>
        </div>
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
        :not-held-options="notHeldOptions ?? null"
        :unsaved-changes="unsavedChanges === true"
        :save-first="saveFirst"
        @submitting="(value) => emit('submitting', value)"
    >
        <Button type="button" variant="outline" class="min-h-11" data-testid="open-outcome-dialog" @click="dialogOpen = true">
            <CalendarX2 class="size-4" /> Não houve aula
        </Button>
    </LessonOutcomeDialog>

    <LessonOutcomeDialog
        v-else-if="retroactiveOutcome && notHeldOptions"
        v-model:open="retroactiveOpen"
        :lesson-ulid="lessonUlid"
        :reasons="reasons"
        :not-held-options="notHeldOptions"
        :retroactive="retroactiveOutcome"
        :lesson-context="lessonContext ?? null"
        :unsaved-changes="unsavedChanges === true"
        :save-first="saveFirst"
        @submitting="(value) => emit('submitting', value)"
    >
        <Button type="button" variant="outline" class="min-h-11" data-testid="open-retroactive-dialog" @click="retroactiveOpen = true">
            <CalendarX2 class="size-4" /> Registar como não realizada
        </Button>
    </LessonOutcomeDialog>
</template>
