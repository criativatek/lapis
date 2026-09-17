<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
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

export type SpecialLessonOutcome = 'teacher_absent' | 'class_external_activity';

/**
 * O diálogo «O que aconteceu nesta aula?» (0.146.0), extraído em 0.147.0 para
 * servir a página da aula E o fecho rápido da semana sem duas cópias.
 *
 * Um só diálogo por página: a semana abre-o para a aula escolhida em vez de
 * montar um por cartão. A decisão e as recusas continuam no servidor
 * (RecordLessonOutcome); aqui só se recolhe a categoria e a nota.
 */
const props = defineProps<{
    lessonUlid: string | null;
    reasons: { value: string; label: string }[];
    initialOutcome?: SpecialLessonOutcome;
    /** «8.º F · 09:30–10:20» — para o professor saber que aula está a fechar. */
    lessonContext?: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ submitting: [boolean] }>();

const form = useForm<{ outcome: SpecialLessonOutcome; reason: string; note: string }>({
    outcome: 'teacher_absent',
    reason: '',
    note: '',
});

watch(open, (value) => {
    if (value && props.initialOutcome) {
        form.outcome = props.initialOutcome;
    }
});

function submit(): void {
    if (props.lessonUlid === null) {
        return;
    }

    emit('submitting', true);
    form.transform((data) =>
        data.outcome === 'teacher_absent'
            ? { outcome: data.outcome, reason: data.reason }
            : { outcome: data.outcome, note: data.note.trim() === '' ? null : data.note },
    ).post(`/lessons/${props.lessonUlid}/outcome`, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
        onFinish: () => emit('submitting', false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <slot />
        <DialogContent class="sm:max-w-md">
            <form class="space-y-4" @submit.prevent="submit">
                <DialogHeader class="space-y-2">
                    <DialogTitle>O que aconteceu nesta aula?</DialogTitle>
                    <DialogDescription>
                        <span v-if="lessonContext" class="block font-medium text-foreground">{{ lessonContext }}</span>
                        O planeamento desta aula passa para a aula seguinte. As faltas em rascunho são descartadas.
                    </DialogDescription>
                </DialogHeader>

                <fieldset class="grid gap-2">
                    <legend class="sr-only">Resultado</legend>
                    <label class="flex min-h-9 items-center gap-2">
                        <input v-model="form.outcome" type="radio" name="outcome" value="teacher_absent" />
                        Professor ausente
                    </label>
                    <label class="flex min-h-9 items-center gap-2">
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
                    <Button type="submit" class="min-h-11" :disabled="form.processing || lessonUlid === null">
                        <Spinner v-if="form.processing" />
                        Registar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
