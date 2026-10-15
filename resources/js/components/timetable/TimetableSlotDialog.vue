<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { capitalizeFirst } from '@/lib/text';
import type { TimetableClassOption, TimetableSlot } from './types';
import { WEEKDAYS } from './types';

/**
 * ADICIONAR OU CORRIGIR UM TEMPO DE AULA, a partir do Horário do Professor.
 *
 * Não há lógica nova aqui: o diálogo fala com as MESMAS rotas `lesson-slots`
 * que o editor da turma (`LessonScheduleEditor`), e é o servidor que decide o
 * que acontece — edita no lugar um tempo sem histórico, versiona um tempo com
 * histórico (fecha a linha antiga na véspera da data de aplicação e abre outra),
 * e nunca apaga nem muda de turma uma aula com sumário, plano ou faltas. O que
 * o diálogo faz é pedir a data de aplicação quando ela é precisa.
 */
const props = defineProps<{
    open: boolean;
    /** `null` = tempo novo. */
    lessonSlot: TimetableSlot | null;
    classes: TimetableClassOption[];
    /** O dia pré-selecionado num tempo novo. */
    defaultDay?: number;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

function todayIsoDate(): string {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}

const form = useForm<{
    class_id: number | null;
    class_group_id: number | null;
    day_of_week: number;
    starts_at: string;
    ends_at: string;
    starts_on: string;
    ends_on: string;
    effective_from: string;
}>({
    class_id: null,
    class_group_id: null,
    day_of_week: 1,
    starts_at: '',
    ends_at: '',
    starts_on: '',
    ends_on: '',
    effective_from: '',
});

const confirmingDelete = ref(false);
const stopFrom = ref('');
const stopErrors = ref<string | null>(null);

/**
 * «Turma · Disciplina»: a disciplina é atributo da turma, por isso escolher a
 * turma certa é também escolher a disciplina certa. A turma atual do tempo
 * entra sempre na lista, mesmo que já não esteja entre as configuráveis (uma
 * turma arquivada numa semana em que ainda estava viva).
 */
const classOptions = computed(() => {
    const options = props.classes.filter((option) => option.id !== undefined);
    const current = props.lessonSlot?.school_class;

    if (
        current?.id !== undefined &&
        !options.some((option) => option.id === current.id)
    ) {
        options.push({
            id: current.id,
            ulid: current.ulid,
            label: current.label,
            subject: props.lessonSlot?.subject ?? '',
        });
    }

    return options;
});

const changesClass = computed(
    () =>
        props.lessonSlot !== null &&
        form.class_id !== null &&
        form.class_id !== (props.lessonSlot.school_class.id ?? null),
);

/**
 * A data de aplicação só aparece quando é precisa: o tempo já tem histórico, ou
 * muda de turma estando já em vigor (se tiver aulas com registos, o servidor
 * versiona e pede-a; se não tiver, edita no lugar e ignora-a).
 */
const askForEffectiveFrom = computed(
    () =>
        props.lessonSlot !== null &&
        (props.lessonSlot.requires_versioning === true ||
            (changesClass.value &&
                props.lessonSlot.already_in_vigor === true) ||
            Boolean(form.errors.effective_from)),
);

watch(
    () => [props.open, props.lessonSlot?.ulid] as const,
    ([isOpen]) => {
        if (!isOpen) {
            return;
        }

        confirmingDelete.value = false;
        stopFrom.value = '';
        stopErrors.value = null;
        form.clearErrors();
        form.class_id =
            props.lessonSlot?.school_class.id ??
            classOptions.value[0]?.id ??
            null;
        form.class_group_id = props.lessonSlot?.class_group_id ?? null;
        form.day_of_week =
            props.lessonSlot?.day_of_week ?? props.defaultDay ?? 1;
        form.starts_at = props.lessonSlot?.starts_at ?? '';
        form.ends_at = props.lessonSlot?.ends_at ?? '';
        form.starts_on = props.lessonSlot?.starts_on ?? '';
        form.ends_on = props.lessonSlot?.ends_on ?? '';
        form.effective_from = todayIsoDate();
    },
    { immediate: true },
);

function close(): void {
    emit('update:open', false);
}

function submit(): void {
    form.transform((data) => ({
        class_id: data.class_id,
        // Mudar de turma limpa o grupo: era da turma antiga.
        class_group_id: changesClass.value ? null : data.class_group_id,
        day_of_week: data.day_of_week,
        starts_at: data.starts_at,
        ends_at: data.ends_at,
        starts_on: data.starts_on,
        ends_on: data.ends_on,
        ...(askForEffectiveFrom.value
            ? { effective_from: data.effective_from }
            : {}),
    }));

    const options = { preserveScroll: true, onSuccess: close };

    if (props.lessonSlot === null) {
        form.post('/lesson-slots', options);

        return;
    }

    form.put(`/lesson-slots/${props.lessonSlot.ulid}`, options);
}

function remove(): void {
    if (props.lessonSlot === null) {
        return;
    }

    stopErrors.value = null;

    router.delete(`/lesson-slots/${props.lessonSlot.ulid}`, {
        data: stopFrom.value ? { effective_from: stopFrom.value } : {},
        preserveScroll: true,
        onSuccess: close,
        onError: (errors: Record<string, string>) => {
            stopErrors.value = errors.effective_from ?? null;
        },
    });
}

const title = computed(() =>
    props.lessonSlot === null ? 'Adicionar aula ao horário' : 'Corrigir aula',
);
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="max-h-[90vh] max-w-lg overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    <template v-if="lessonSlot === null">
                        Escolhe a turma e a hora. A aula passa a repetir-se
                        todas as semanas.
                    </template>
                    <template v-else>
                        Corrige a turma, o dia, as horas ou a vigência. O que já
                        foi lecionado ou sumariado não se perde nem muda de
                        turma.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-3" @submit.prevent="submit">
                <div class="grid gap-1.5">
                    <Label for="timetable-slot-class">Turma · Disciplina</Label>
                    <NativeSelect
                        id="timetable-slot-class"
                        v-model="form.class_id"
                        required
                    >
                        <option
                            v-for="option in classOptions"
                            :key="option.ulid"
                            :value="option.id"
                        >
                            {{ option.label }} · {{ option.subject }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.class_id" />
                    <p
                        v-if="lessonSlot?.class_group_label && !changesClass"
                        class="text-xs text-muted-foreground"
                    >
                        Grupo {{ lessonSlot.class_group_label }} — os grupos
                        gerem-se na página da turma.
                    </p>
                    <p
                        v-if="changesClass"
                        class="text-xs text-muted-foreground"
                    >
                        As aulas já lecionadas ou com sumário ficam na turma
                        original; a nova turma recebe as aulas a partir da data
                        de aplicação.
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-1.5">
                        <Label for="timetable-slot-day">Dia</Label>
                        <NativeSelect
                            id="timetable-slot-day"
                            v-model="form.day_of_week"
                        >
                            <option
                                v-for="(weekday, index) in WEEKDAYS"
                                :key="weekday"
                                :value="index + 1"
                            >
                                {{ capitalizeFirst(weekday) }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.day_of_week" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="timetable-slot-start">Início</Label>
                        <Input
                            id="timetable-slot-start"
                            v-model="form.starts_at"
                            type="time"
                            required
                        />
                        <InputError :message="form.errors.starts_at" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="timetable-slot-end">Fim</Label>
                        <Input
                            id="timetable-slot-end"
                            v-model="form.ends_at"
                            type="time"
                            required
                        />
                        <InputError :message="form.errors.ends_at" />
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="timetable-slot-starts-on"
                            >Válido desde
                            <span class="font-normal text-muted-foreground"
                                >(opcional)</span
                            ></Label
                        >
                        <Input
                            id="timetable-slot-starts-on"
                            v-model="form.starts_on"
                            type="date"
                        />
                        <InputError :message="form.errors.starts_on" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="timetable-slot-ends-on"
                            >Válido até
                            <span class="font-normal text-muted-foreground"
                                >(opcional)</span
                            ></Label
                        >
                        <Input
                            id="timetable-slot-ends-on"
                            v-model="form.ends_on"
                            type="date"
                        />
                        <InputError :message="form.errors.ends_on" />
                    </div>
                </div>

                <div
                    v-if="askForEffectiveFrom"
                    class="grid gap-1.5 rounded-md border border-dashed p-3"
                >
                    <Label for="timetable-slot-effective-from"
                        >Aplicar alteração a partir de</Label
                    >
                    <Input
                        id="timetable-slot-effective-from"
                        v-model="form.effective_from"
                        type="date"
                        required
                    />
                    <InputError :message="form.errors.effective_from" />
                    <p class="text-xs text-muted-foreground">
                        Este horário já está em vigor. As aulas até ao dia
                        anterior mantêm a configuração atual; a partir desta
                        data passa a vigorar a nova.
                    </p>
                </div>

                <DialogFooter class="gap-2 sm:justify-between">
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="lessonSlot !== null && !confirmingDelete"
                            type="button"
                            variant="ghost"
                            class="text-destructive"
                            data-remove-slot
                            @click="confirmingDelete = true"
                        >
                            Eliminar aula
                        </Button>
                        <Button
                            v-if="lessonSlot !== null"
                            as-child
                            variant="ghost"
                        >
                            <Link
                                :href="`/classes/${lessonSlot.school_class.ulid}#horario`"
                                >Abrir a turma</Link
                            >
                        </Button>
                    </div>
                    <div class="flex gap-2">
                        <Button type="button" variant="secondary" @click="close"
                            >Cancelar</Button
                        >
                        <Button type="submit" :disabled="form.processing">{{
                            form.processing ? 'A guardar…' : 'Guardar'
                        }}</Button>
                    </div>
                </DialogFooter>
            </form>

            <div
                v-if="lessonSlot !== null && confirmingDelete"
                class="space-y-3 rounded-md border border-destructive/40 p-3"
                data-confirm-remove-slot
            >
                <p class="text-sm font-medium">
                    Eliminar esta aula do horário?
                </p>
                <p class="text-xs text-muted-foreground">
                    O histórico é preservado: as aulas já lecionadas ou com
                    sumário ficam como estão, e só as aulas futuras vazias saem.
                    Se indicares uma data, o horário deixa de vigorar a partir
                    dela.
                </p>
                <div class="grid gap-1.5">
                    <Label for="timetable-slot-stop-from"
                        >Deixa de vigorar a partir de
                        <span class="font-normal text-muted-foreground"
                            >(opcional)</span
                        ></Label
                    >
                    <Input
                        id="timetable-slot-stop-from"
                        v-model="stopFrom"
                        type="date"
                    />
                    <InputError :message="stopErrors ?? undefined" />
                </div>
                <div class="flex gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="confirmingDelete = false"
                        >Cancelar</Button
                    >
                    <Button
                        type="button"
                        variant="destructive"
                        data-confirm-remove
                        @click="remove"
                        >Eliminar</Button
                    >
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
