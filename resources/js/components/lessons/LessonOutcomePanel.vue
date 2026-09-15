<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CalendarX2 } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { Spinner } from '@/components/ui/spinner';

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
const props = defineProps<{
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
const form = useForm<{ outcome: 'teacher_absent' | 'class_external_activity'; reason: string; note: string }>({
    outcome: 'teacher_absent',
    reason: '',
    note: '',
});

function submit(): void {
    emit('submitting', true);
    form.transform((data) =>
        data.outcome === 'teacher_absent'
            ? { outcome: data.outcome, reason: data.reason }
            : { outcome: data.outcome, note: data.note.trim() === '' ? null : data.note },
    ).post(`/lessons/${props.lessonUlid}/outcome`, {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset();
        },
        onFinish: () => emit('submitting', false),
    });
}
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

    <Dialog v-if="canRecord" v-model:open="dialogOpen">
        <Button type="button" variant="outline" class="min-h-11" data-testid="open-outcome-dialog" @click="dialogOpen = true">
            <CalendarX2 class="size-4" /> Não houve aula
        </Button>
        <DialogContent class="sm:max-w-md">
            <form class="space-y-4" @submit.prevent="submit">
                <DialogHeader class="space-y-2">
                    <DialogTitle>O que aconteceu nesta aula?</DialogTitle>
                    <DialogDescription>
                        O planeamento desta aula passa para a aula seguinte. As faltas em rascunho são descartadas.
                    </DialogDescription>
                </DialogHeader>

                <fieldset class="grid gap-2">
                    <legend class="sr-only">Resultado</legend>
                    <label class="flex items-center gap-2">
                        <input v-model="form.outcome" type="radio" name="outcome" value="teacher_absent" />
                        Professor ausente
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="form.outcome" type="radio" name="outcome" value="class_external_activity" />
                        Turma em outras atividades letivas
                    </label>
                    <InputError :message="form.errors.outcome" />
                </fieldset>

                <div v-if="form.outcome === 'teacher_absent'" class="grid gap-2">
                    <Label for="outcome-reason">Motivo</Label>
                    <NativeSelect id="outcome-reason" v-model="form.reason" name="reason" required>
                        <option value="" disabled>Escolhe o motivo</option>
                        <option v-for="reason in reasons" :key="reason.value" :value="reason.value">{{ reason.label }}</option>
                    </NativeSelect>
                    <InputError :message="form.errors.reason" />
                </div>

                <div v-else class="grid gap-2">
                    <Label for="outcome-note">Atividade (opcional)</Label>
                    <input
                        id="outcome-note"
                        v-model="form.note"
                        name="note"
                        maxlength="160"
                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                        placeholder="Ex.: visita de estudo"
                        aria-describedby="outcome-note-hint"
                    />
                    <p id="outcome-note-hint" class="text-xs text-muted-foreground">Evite incluir dados pessoais desnecessários.</p>
                    <InputError :message="form.errors.note" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline" class="min-h-11">Cancelar</Button>
                    </DialogClose>
                    <Button type="submit" class="min-h-11" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        Registar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
