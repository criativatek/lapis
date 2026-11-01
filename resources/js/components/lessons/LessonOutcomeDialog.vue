<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import { ref, watch } from 'vue';
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
import type { NotHeldOptions } from '@/lib/lessonOutcomeOptions';
import { SURFACE } from '@/lib/surfaces';

export type SpecialLessonOutcome = 'teacher_absent' | 'class_external_activity' | 'not_held';

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
    /** «Aula não realizada»: causas e especificações. `null`/ausente = funcionalidade desligada. */
    notHeldOptions?: NotHeldOptions | null;
    /**
     * Registo RETROATIVO: a aula é anterior a aulas já fechadas. O diálogo só oferece «Aula não
     * realizada», diz o que acontece à numeração, mostra as razões que impedem (vindas do
     * servidor) e pede confirmação antes de guardar.
     */
    retroactive?: { closed_after: string; blockers: string[] } | null;
    initialOutcome?: SpecialLessonOutcome;
    /** «8.º F · 09:30–10:20» — para o professor saber que aula está a fechar. */
    lessonContext?: string | null;
    /**
     * A página tem texto por guardar nesta aula (0.171.0). O planeamento que
     * passa para a aula seguinte é o GUARDADO: registar por cima de texto por
     * guardar deixava-o na aula que já fechou. Pede-se primeiro para guardar.
     */
    unsavedChanges?: boolean;
    /**
     * Grava o que a página tem por guardar; resolve `true` só se a gravação foi
     * aceite. Recusada (conflito de versão, número por confirmar, erro), o
     * diálogo fecha-se para a razão — que está na página — ficar à vista.
     */
    saveFirst?: () => Promise<boolean>;
}>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ submitting: [boolean]; recorded: [] }>();

const savingFirst = ref(false);
const confirming = ref(false);

async function saveBeforeRecording(): Promise<void> {
    if (props.saveFirst === undefined || savingFirst.value) {
        return;
    }

    savingFirst.value = true;

    try {
        if (!(await props.saveFirst())) {
            open.value = false;
        }
    } finally {
        savingFirst.value = false;
    }
}

const form = useForm<{
    outcome: SpecialLessonOutcome;
    reason: string;
    note: string;
    cause: string;
    situation: string;
    cause_note: string;
}>({
    outcome: props.retroactive ? 'not_held' : 'teacher_absent',
    reason: '',
    note: '',
    cause: '',
    situation: '',
    cause_note: '',
});

// A especificação só existe com «Greve»; a descrição só com «Outra causa». Mudar
// de causa limpa o que deixou de se aplicar, para nunca viajar por engano.
watch(
    () => form.cause,
    (cause) => {
        if (cause !== 'strike') {
            form.situation = '';
        }

        if (cause !== 'other') {
            form.cause_note = '';
        }
    },
);

watch(open, (value) => {
    confirming.value = false;

    if (value && props.retroactive) {
        form.outcome = 'not_held';
    } else if (value && props.initialOutcome) {
        form.outcome = props.initialOutcome;
    }
});

function submit(): void {
    if (props.lessonUlid === null || props.unsavedChanges) {
        return;
    }

    if (props.retroactive) {
        if (props.retroactive.blockers.length > 0) {
            return;
        }

        // Primeiro mostra-se o que vai acontecer; só a segunda confirmação grava.
        if (!confirming.value) {
            confirming.value = true;

            return;
        }
    }

    emit('submitting', true);
    form.transform((data) => {
        if (data.outcome === 'teacher_absent') {
            return { outcome: data.outcome, reason: data.reason };
        }

        if (data.outcome === 'not_held') {
            return {
                outcome: data.outcome,
                cause: data.cause,
                ...(data.cause === 'strike' && data.situation !== '' ? { situation: data.situation } : {}),
                ...(data.cause === 'other' ? { cause_note: data.cause_note.trim() } : {}),
                ...(props.retroactive ? { retroactive: true } : {}),
            };
        }

        return { outcome: data.outcome, note: data.note.trim() === '' ? null : data.note };
    }).post(`/lessons/${props.lessonUlid}/outcome`, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
            emit('recorded');
        },
        onFinish: () => emit('submitting', false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <slot />
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-md">
            <form class="space-y-4" @submit.prevent="submit">
                <DialogHeader class="space-y-2">
                    <DialogTitle>O que aconteceu nesta aula?</DialogTitle>
                    <DialogDescription>
                        <span v-if="lessonContext" class="block font-medium text-foreground">{{ lessonContext }}</span>
                        O planeamento desta aula passa para a aula seguinte. As faltas em rascunho são descartadas.
                    </DialogDescription>
                </DialogHeader>

                <div v-if="unsavedChanges" :class="['space-y-3 rounded-xl border p-4 text-sm', SURFACE.sky]" data-testid="outcome-unsaved">
                    <p class="flex items-start gap-2">
                        <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        Tens alterações por guardar no sumário desta aula. Guarda-as primeiro: a preparação que passa
                        para a aula seguinte é a que está guardada.
                    </p>
                    <Button
                        v-if="saveFirst"
                        type="button"
                        class="min-h-11"
                        data-testid="outcome-save-first"
                        :disabled="savingFirst"
                        @click="saveBeforeRecording"
                    >
                        <Spinner v-if="savingFirst" />
                        {{ savingFirst ? 'A guardar…' : 'Guardar alterações' }}
                    </Button>
                </div>

                <div
                    v-if="retroactive"
                    :class="['space-y-2 rounded-xl border p-4 text-sm', SURFACE.sky]"
                    data-testid="retroactive-info"
                >
                    <p>
                        A aula de {{ retroactive.closed_after }}, depois desta, já está fechada. Podes registar esta aula como
                        não realizada: nenhuma aula já lecionada muda de número, e fica uma lacuna na numeração no sítio
                        onde esta aula estava.
                    </p>
                    <div v-if="retroactive.blockers.length > 0" data-testid="retroactive-blockers">
                        <p class="font-medium">Não é possível porque esta aula:</p>
                        <ul class="list-disc pl-5">
                            <li v-for="blocker in retroactive.blockers" :key="blocker">{{ blocker }}</li>
                        </ul>
                        <p>Trata-a individualmente.</p>
                    </div>
                </div>

                <fieldset v-if="!retroactive" class="grid gap-2">
                    <legend class="sr-only">Resultado</legend>
                    <label class="flex min-h-9 items-center gap-2">
                        <input v-model="form.outcome" type="radio" name="outcome" value="teacher_absent" />
                        Professor ausente
                    </label>
                    <label class="flex min-h-9 items-center gap-2">
                        <input v-model="form.outcome" type="radio" name="outcome" value="class_external_activity" />
                        Turma em outras atividades letivas
                    </label>
                    <label v-if="notHeldOptions" class="flex min-h-9 items-center gap-2" data-testid="outcome-not-held">
                        <input v-model="form.outcome" type="radio" name="outcome" value="not_held" />
                        Aula não realizada
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

                <div v-else-if="form.outcome === 'not_held' && notHeldOptions" class="grid gap-3" data-testid="not-held-fields">
                    <div class="grid gap-2">
                        <Label for="outcome-cause">Causa</Label>
                        <NativeSelect id="outcome-cause" v-model="form.cause" name="cause" required>
                            <option value="" disabled>Escolhe a causa</option>
                            <option v-for="cause in notHeldOptions.causes" :key="cause.value" :value="cause.value">{{ cause.label }}</option>
                        </NativeSelect>
                        <InputError :message="form.errors.cause" />
                    </div>

                    <fieldset v-if="form.cause === 'strike'" class="grid gap-2" data-testid="strike-specification">
                        <legend class="text-sm font-medium">Queres especificar? (opcional)</legend>
                        <label class="flex min-h-9 items-center gap-2">
                            <input v-model="form.situation" type="radio" name="situation" value="" />
                            Não especificar
                        </label>
                        <label v-for="option in notHeldOptions.specifications" :key="option.value" class="flex min-h-9 items-center gap-2">
                            <input v-model="form.situation" type="radio" name="situation" :value="option.value" />
                            {{ option.label }}
                        </label>
                        <p class="text-xs text-muted-foreground">Só tu vês esta especificação.</p>
                        <InputError :message="form.errors.situation" />
                    </fieldset>

                    <div v-if="form.cause === 'other'" class="grid gap-2">
                        <Label for="outcome-cause-note">Descrição breve</Label>
                        <input
                            id="outcome-cause-note"
                            v-model="form.cause_note"
                            name="cause_note"
                            maxlength="160"
                            required
                            class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                            aria-describedby="outcome-cause-note-hint"
                        />
                        <p id="outcome-cause-note-hint" class="text-xs text-muted-foreground">Evita incluir dados pessoais.</p>
                        <InputError :message="form.errors.cause_note" />
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Esta aula não conta como lecionada nem leva número. O planeamento passa para a aula seguinte.
                    </p>
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

                <div v-if="confirming" :class="['space-y-1 rounded-xl border p-4 text-sm', SURFACE.amber]" data-testid="retroactive-confirm">
                    <p class="font-medium">Confirmas?</p>
                    <p>
                        {{ lessonContext }} fica «Aula não realizada» e sem número. Nenhuma aula lecionada muda de número.
                    </p>
                    <Button type="button" variant="ghost" class="min-h-11" @click="confirming = false">Rever</Button>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline" class="min-h-11">Cancelar</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        class="min-h-11"
                        :disabled="form.processing || lessonUlid === null || unsavedChanges === true || (retroactive?.blockers.length ?? 0) > 0"
                    >
                        <Spinner v-if="form.processing" />
                        {{ retroactive ? (confirming ? 'Confirmar registo' : 'Continuar') : 'Registar' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
