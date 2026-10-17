<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import type {
    TimetableActivity,
    TimetableActivityKindOption,
    TimetableClassOption,
} from './types';
import { WEEKDAYS } from './types';

/**
 * «OUTRAS ATIVIDADES» (0.168.0; 0.170.0): direção de turma, apoio a alunos,
 * clubes, reuniões, coordenação. Não são aulas — não geram aulas nem
 * assiduidade e não entram na numeração das lições. Ficam no horário do
 * professor, numa tabela própria, e cada sessão tem o seu sumário.
 *
 * Eliminar uma atividade que já tem sessões com registos NÃO a apaga: fecha-a
 * a partir de uma data e o histórico fica. O diálogo di-lo antes de confirmar.
 */
const props = defineProps<{
    open: boolean;
    /** `null` = atividade nova. */
    activity: TimetableActivity | null;
    kinds: TimetableActivityKindOption[];
    classes: TimetableClassOption[];
    defaultDay?: number;
    /** Hoje (`Y-m-d`, do servidor): a data por omissão de «deixa de vigorar a partir de». */
    today?: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm<{
    kind: string;
    title: string;
    class_ulid: string;
    day_of_week: number;
    starts_at: string;
    ends_at: string;
    starts_on: string;
    ends_on: string;
    numbering_enabled: boolean;
    numbering_start: number | string;
}>({
    kind: 'other',
    title: '',
    class_ulid: '',
    day_of_week: 1,
    starts_at: '',
    ends_at: '',
    starts_on: '',
    ends_on: '',
    numbering_enabled: false,
    numbering_start: 1,
});

const confirmingDelete = ref(false);
const effectiveFrom = ref('');
const removeError = ref<string | null>(null);
const recordsCount = computed(() => props.activity?.records_count ?? 0);

watch(
    () => [props.open, props.activity?.ulid] as const,
    ([isOpen]) => {
        if (!isOpen) {
            return;
        }

        confirmingDelete.value = false;
        removeError.value = null;
        effectiveFrom.value = props.today ?? '';
        form.clearErrors();
        form.kind = props.activity?.kind ?? props.kinds[0]?.value ?? 'other';
        form.title = props.activity?.title ?? '';
        form.class_ulid = props.activity?.school_class?.ulid ?? '';
        form.day_of_week = props.activity?.day_of_week ?? props.defaultDay ?? 1;
        form.starts_at = props.activity?.starts_at ?? '';
        form.ends_at = props.activity?.ends_at ?? '';
        form.starts_on = props.activity?.starts_on ?? '';
        form.ends_on = props.activity?.ends_on ?? '';
        form.numbering_enabled = props.activity?.numbering_enabled ?? false;
        form.numbering_start = props.activity?.numbering_start ?? 1;
    },
    { immediate: true },
);

function close(): void {
    emit('update:open', false);
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        class_ulid: data.class_ulid === '' ? null : data.class_ulid,
        numbering_start:
            data.numbering_start === '' ? 1 : Number(data.numbering_start),
    }));

    const options = { preserveScroll: true, onSuccess: close };

    if (props.activity === null) {
        form.post('/timetable-activities', options);

        return;
    }

    form.put(`/timetable-activities/${props.activity.ulid}`, options);
}

function remove(): void {
    if (props.activity === null) {
        return;
    }

    router.delete(`/timetable-activities/${props.activity.ulid}`, {
        // A data só conta quando há sessões com registos (o bloco fecha).
        data:
            recordsCount.value > 0 && effectiveFrom.value !== ''
                ? { effective_from: effectiveFrom.value }
                : {},
        preserveScroll: true,
        onSuccess: close,
        onError: (errors) => {
            removeError.value = errors.effective_from ?? null;
        },
    });
}

const title = computed(() =>
    props.activity === null
        ? 'Adicionar outra atividade'
        : 'Editar outra atividade',
);
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="max-h-[90vh] max-w-lg overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    Direção de turma, apoio, clube, reuniões, coordenação…
                    Aparecem no teu horário, não geram aulas e cada sessão tem o
                    seu sumário.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-3" @submit.prevent="submit">
                <div class="grid items-start gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="timetable-activity-kind">Tipo</Label>
                        <NativeSelect
                            id="timetable-activity-kind"
                            v-model="form.kind"
                        >
                            <option
                                v-for="kind in kinds"
                                :key="kind.value"
                                :value="kind.value"
                            >
                                {{ kind.label }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.kind" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="timetable-activity-title">Designação</Label>
                        <Input
                            id="timetable-activity-title"
                            v-model="form.title"
                            maxlength="60"
                            placeholder="Ex.: DT 6.º D"
                            required
                        />
                        <InputError :message="form.errors.title" />
                    </div>
                </div>

                <div class="grid gap-1.5">
                    <Label for="timetable-activity-class"
                        >Turma
                        <span class="font-normal text-muted-foreground"
                            >(opcional)</span
                        ></Label
                    >
                    <NativeSelect
                        id="timetable-activity-class"
                        v-model="form.class_ulid"
                    >
                        <option value="">Sem turma</option>
                        <option
                            v-for="option in classes"
                            :key="option.ulid"
                            :value="option.ulid"
                        >
                            {{ option.label }} · {{ option.subject }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.class_ulid" />
                </div>

                <div class="grid items-start gap-3 sm:grid-cols-3">
                    <div class="grid gap-1.5">
                        <Label for="timetable-activity-day">Dia</Label>
                        <NativeSelect
                            id="timetable-activity-day"
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
                        <Label for="timetable-activity-start">Início</Label>
                        <Input
                            id="timetable-activity-start"
                            v-model="form.starts_at"
                            type="time"
                            required
                        />
                        <InputError :message="form.errors.starts_at" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="timetable-activity-end">Fim</Label>
                        <Input
                            id="timetable-activity-end"
                            v-model="form.ends_at"
                            type="time"
                            required
                        />
                        <InputError :message="form.errors.ends_at" />
                    </div>
                </div>

                <div class="grid items-start gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="timetable-activity-starts-on"
                            >Válido desde
                            <span class="font-normal text-muted-foreground"
                                >(opcional)</span
                            ></Label
                        >
                        <Input
                            id="timetable-activity-starts-on"
                            v-model="form.starts_on"
                            type="date"
                        />
                        <InputError :message="form.errors.starts_on" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="timetable-activity-ends-on"
                            >Válido até
                            <span class="font-normal text-muted-foreground"
                                >(opcional)</span
                            ></Label
                        >
                        <Input
                            id="timetable-activity-ends-on"
                            v-model="form.ends_on"
                            type="date"
                        />
                        <InputError :message="form.errors.ends_on" />
                    </div>
                </div>

                <div class="grid items-start gap-3 sm:grid-cols-2">
                    <div class="flex items-center gap-2 pt-6">
                        <Checkbox
                            id="timetable-activity-numbering"
                            :model-value="form.numbering_enabled"
                            data-numbering-enabled
                            @update:model-value="
                                (value) =>
                                    (form.numbering_enabled = value === true)
                            "
                        />
                        <Label for="timetable-activity-numbering"
                            >Numerar as sessões</Label
                        >
                    </div>
                    <div v-if="form.numbering_enabled" class="grid gap-1.5">
                        <Label for="timetable-activity-numbering-start"
                            >Número inicial</Label
                        >
                        <Input
                            id="timetable-activity-numbering-start"
                            v-model="form.numbering_start"
                            type="number"
                            min="1"
                            max="9999"
                        />
                        <InputError :message="form.errors.numbering_start" />
                    </div>
                </div>

                <DialogFooter class="gap-2 sm:justify-between">
                    <div>
                        <Button
                            v-if="activity !== null && !confirmingDelete"
                            type="button"
                            variant="ghost"
                            class="text-destructive"
                            data-remove-activity
                            @click="confirmingDelete = true"
                        >
                            {{ recordsCount > 0 ? 'Terminar' : 'Eliminar' }}
                        </Button>
                        <span
                            v-if="activity !== null && confirmingDelete"
                            class="grid gap-2 text-sm"
                            data-confirm-remove-activity
                        >
                            <span v-if="recordsCount > 0" data-closes-activity>
                                Esta atividade tem
                                {{ recordsCount }}
                                {{
                                    recordsCount === 1
                                        ? 'sessão com registos'
                                        : 'sessões com registos'
                                }}: não é apagada. Deixa de vigorar a partir da
                                data escolhida e o histórico fica guardado.
                            </span>
                            <span v-else>Eliminar esta atividade?</span>
                            <span v-if="recordsCount > 0" class="grid gap-1">
                                <Label for="timetable-activity-effective-from"
                                    >Deixa de vigorar a partir de</Label
                                >
                                <Input
                                    id="timetable-activity-effective-from"
                                    v-model="effectiveFrom"
                                    type="date"
                                    :min="today"
                                />
                                <InputError
                                    :message="removeError ?? undefined"
                                />
                            </span>
                            <span class="flex items-center gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="destructive"
                                    data-confirm-remove
                                    @click="remove"
                                    >{{
                                        recordsCount > 0
                                            ? 'Sim, terminar'
                                            : 'Sim, eliminar'
                                    }}</Button
                                >
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="secondary"
                                    @click="confirmingDelete = false"
                                    >Não</Button
                                >
                            </span>
                        </span>
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
        </DialogContent>
    </Dialog>
</template>
