<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { capitalizeFirst } from '@/lib/text';

export type RecurringLessonSlot = {
    ulid: string;
    day_of_week: number;
    starts_at: string;
    ends_at: string;
    starts_on: string | null;
    ends_on: string | null;
    already_in_vigor: boolean;
    requires_versioning: boolean;
    /** `null` = turma inteira. É o que todos os tempos já existentes dizem. */
    class_group_id: number | null;
    class_group_label: string | null;
};

const props = defineProps<{
    classId: number;
    slots: RecurringLessonSlot[];
    /**
     * Os grupos da turma. Lista vazia numa turma que não está desdobrada — e
     * nesse caso o campo «Participantes» não chega a aparecer: uma escolha com
     * uma só opção é ruído (§12 do briefing).
     */
    groups: { ulid: string; id: number; label: string; archived: boolean }[];
}>();

// Só os grupos que ainda aceitam trabalho novo. Um grupo arquivado continua
// legível nos tempos que já o usam — a lista mostra o rótulo que o servidor
// mandou —, mas não pode ser ESCOLHIDO para um tempo novo nem numa revisão.
const selectableGroups = computed(() =>
    props.groups.filter((group) => !group.archived),
);

const weekdays = [
    'segunda-feira',
    'terça-feira',
    'quarta-feira',
    'quinta-feira',
    'sexta-feira',
    'sábado',
    'domingo',
];
const editingUlid = ref<string | null>(null);
const form = useForm<{
    class_id: number;
    class_group_id: number | null;
    day_of_week: number;
    starts_at: string;
    ends_at: string;
    starts_on: string;
    ends_on: string;
    effective_from: string;
}>({
    class_id: props.classId,
    class_group_id: null,
    day_of_week: 1,
    starts_at: '',
    ends_at: '',
    starts_on: '',
    ends_on: '',
    effective_from: '',
});

const editingSlotRequiresVersioning = computed(
    () =>
        props.slots.find((slot) => slot.ulid === editingUlid.value)
            ?.requires_versioning ?? false,
);

function todayIsoDate(): string {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}

function resetForm(): void {
    editingUlid.value = null;
    form.reset();
    form.class_id = props.classId;
    form.class_group_id = null;
    form.effective_from = '';
    form.clearErrors();
}

function edit(slot: RecurringLessonSlot): void {
    editingUlid.value = slot.ulid;
    form.class_id = props.classId;
    form.class_group_id = slot.class_group_id;
    form.day_of_week = slot.day_of_week;
    form.starts_at = slot.starts_at;
    form.ends_at = slot.ends_at;
    form.starts_on = slot.starts_on ?? '';
    form.ends_on = slot.ends_on ?? '';
    // Only a UI default — the server independently validates effective_from
    // against "today" in the organization's own timezone regardless of what
    // the browser's clock defaulted it to.
    form.effective_from = slot.requires_versioning ? todayIsoDate() : '';
    form.clearErrors();
}

function submit(): void {
    // effective_from only means anything when revising an already-in-vigor
    // slot (LessonScheduleController::update()) — creating a slot, or editing
    // one that has not started yet, sends no such field at all rather than
    // an empty string for the backend to have to ignore.
    const includeEffectiveFrom =
        editingUlid.value !== null && editingSlotRequiresVersioning.value;

    form.transform((data) =>
        includeEffectiveFrom
            ? data
            : {
                  class_id: data.class_id,
                  class_group_id: data.class_group_id,
                  day_of_week: data.day_of_week,
                  starts_at: data.starts_at,
                  ends_at: data.ends_at,
                  starts_on: data.starts_on,
                  ends_on: data.ends_on,
              },
    );

    const options = { preserveScroll: true, onSuccess: resetForm };

    if (editingUlid.value === null) {
        form.post('/lesson-slots', options);

        return;
    }

    form.put(`/lesson-slots/${editingUlid.value}`, options);
}

function remove(slot: RecurringLessonSlot): void {
    const message = slot.already_in_vigor
        ? `Terminar o horário de ${weekdays[slot.day_of_week - 1]} às ${slot.starts_at} a partir de hoje? O histórico é preservado.`
        : `Remover o horário de ${weekdays[slot.day_of_week - 1]} às ${slot.starts_at}?`;

    if (confirm(message)) {
        router.delete(`/lesson-slots/${slot.ulid}`, { preserveScroll: true });
    }
}
</script>

<template>
    <section class="space-y-4" aria-labelledby="lesson-schedule-heading">
        <div>
            <h2 id="lesson-schedule-heading" class="text-sm font-semibold">
                Horário de aulas
            </h2>
            <p class="mt-1 text-xs text-muted-foreground">
                Define as aulas recorrentes desta turma. A vista semanal cria as
                ocorrências quando precisares.
            </p>
        </div>

        <ul
            v-if="slots.length"
            class="divide-y rounded-lg border"
            aria-label="Horário recorrente"
        >
            <li
                v-for="slot in slots"
                :key="slot.ulid"
                class="flex flex-wrap items-center justify-between gap-3 p-3"
            >
                <div class="min-w-0 text-sm">
                    <p class="font-medium">
                        {{ capitalizeFirst(weekdays[slot.day_of_week - 1]) }} ·
                        {{ slot.starts_at }}–{{ slot.ends_at }}
                        <span
                            v-if="slot.class_group_label"
                            class="ml-1 rounded-full bg-secondary px-2 py-0.5 text-xs font-normal"
                            >{{ slot.class_group_label }}</span
                        >
                    </p>
                    <p
                        v-if="slot.starts_on || slot.ends_on"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Vigência: {{ slot.starts_on ?? 'início do ano' }} a
                        {{ slot.ends_on ?? 'fim do ano' }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="min-h-10"
                        @click="edit(slot)"
                        ><Pencil class="size-4" /> Editar</Button
                    >
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="min-h-10 min-w-10 text-destructive"
                        :aria-label="`Remover horário de ${weekdays[slot.day_of_week - 1]}`"
                        @click="remove(slot)"
                        ><Trash2 class="size-4"
                    /></Button>
                </div>
            </li>
        </ul>
        <p
            v-else
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
        >
            Ainda não há um horário configurado para esta turma.
        </p>

        <form
            class="space-y-3 rounded-lg border bg-muted/20 p-4"
            @submit.prevent="submit"
        >
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-medium">
                    {{ editingUlid ? 'Editar horário' : 'Adicionar horário' }}
                </h3>
                <Button
                    v-if="editingUlid"
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="resetForm"
                    ><X class="size-4" /> Cancelar</Button
                >
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="grid gap-1.5">
                    <Label for="slot-weekday">Dia</Label>
                    <select
                        id="slot-weekday"
                        v-model.number="form.day_of_week"
                        class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option
                            v-for="(weekday, index) in weekdays"
                            :key="weekday"
                            :value="index + 1"
                        >
                            {{ capitalizeFirst(weekday) }}
                        </option>
                    </select>
                    <InputError :message="form.errors.day_of_week" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="slot-start">Início</Label
                    ><Input
                        id="slot-start"
                        v-model="form.starts_at"
                        type="time"
                        required
                    /><InputError :message="form.errors.starts_at" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="slot-end">Fim</Label
                    ><Input
                        id="slot-end"
                        v-model="form.ends_at"
                        type="time"
                        required
                    /><InputError :message="form.errors.ends_at" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="slot-starts-on"
                        >Válido desde
                        <span class="font-normal text-muted-foreground"
                            >(opcional)</span
                        ></Label
                    ><Input
                        id="slot-starts-on"
                        v-model="form.starts_on"
                        type="date"
                    /><InputError :message="form.errors.starts_on" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="slot-ends-on"
                        >Válido até
                        <span class="font-normal text-muted-foreground"
                            >(opcional)</span
                        ></Label
                    ><Input
                        id="slot-ends-on"
                        v-model="form.ends_on"
                        type="date"
                    /><InputError :message="form.errors.ends_on" />
                </div>
            </div>
            <!--
                «Participantes» só existe numa turma desdobrada. Numa turma sem
                grupos o campo não aparece de todo — não há escolha nenhuma a
                fazer, e um seletor com uma opção só seria ruído que sugeriria
                uma configuração em falta.
            -->
            <fieldset v-if="selectableGroups.length" class="grid gap-1.5">
                <legend class="mb-1.5 text-sm font-medium">Participantes</legend>
                <label
                    class="flex min-h-10 items-center gap-2 text-sm"
                    :for="`slot-participants-all`"
                >
                    <input
                        id="slot-participants-all"
                        v-model="form.class_group_id"
                        type="radio"
                        name="slot-participants"
                        :value="null"
                        class="size-4"
                    />
                    Turma inteira
                </label>
                <label
                    v-for="group in selectableGroups"
                    :key="group.ulid"
                    class="flex min-h-10 items-center gap-2 text-sm"
                    :for="`slot-participants-${group.ulid}`"
                >
                    <input
                        :id="`slot-participants-${group.ulid}`"
                        v-model="form.class_group_id"
                        type="radio"
                        name="slot-participants"
                        :value="group.id"
                        class="size-4"
                    />
                    {{ group.label }}
                </label>
                <InputError :message="form.errors.class_group_id" />
            </fieldset>

            <div
                v-if="editingSlotRequiresVersioning"
                class="grid gap-1.5 rounded-md border border-dashed p-3"
            >
                <Label for="slot-effective-from"
                    >Aplicar alteração a partir de</Label
                ><Input
                    id="slot-effective-from"
                    v-model="form.effective_from"
                    type="date"
                    required
                /><InputError :message="form.errors.effective_from" />
                <p class="text-xs text-muted-foreground">
                    Este horário já está em vigor. As aulas até ao dia
                    anterior mantêm a configuração atual; a partir desta data
                    passa a vigorar a nova.
                </p>
            </div>
            <InputError :message="form.errors.class_id" />
            <Button type="submit" class="min-h-11" :disabled="form.processing"
                ><Plus v-if="!editingUlid" class="size-4" />{{
                    form.processing
                        ? 'A guardar…'
                        : editingUlid
                          ? 'Guardar alterações'
                          : 'Adicionar horário'
                }}</Button
            >
        </form>
    </section>
</template>
