<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InstrumentDomainAllocations from './InstrumentDomainAllocations.vue';

type Option = { id: number; label: string; default_purpose?: string };

// A sentinel, never a real id (those start at 1) — selecting it reveals the
// custom-name input below, and the server resolves/creates the teacher's own
// InstrumentType from that name instead of an existing one.
const OTHER_TYPE_ID = 0;

// The shape the server sends/expects: domain shares as percentages of the
// item's own points_possible.
type WireAllocation = { domain_id: number; allocation_percent: number };
type WireItemRow = {
    ulid?: string;
    group_index?: number;
    code: string;
    label: string;
    points_possible: number;
    is_bonus: boolean;
    has_scores?: boolean;
    domains: WireAllocation[];
};

// A section of the instrument — "Grupo I", "Oralidade". A null label is the
// implicit group every instrument has: the teacher never sees it, and the form
// stays exactly as simple as it was before groups existed.
type WireGroupRow = { ulid?: string; label: string | null };
// In the form the label is always a string — empty means "still the implicit
// group", and submit() turns it back into null.
type GroupRow = { ulid?: string; label: string };

// The shape this form edits: the teacher types points per domain directly,
// never a percentage — allocation_percent is derived only at submit time, so
// the calculation engine and schema never need to know points were the input.
type Allocation = { domain_id: number; points: number };
type ItemRow = {
    ulid?: string;
    // Which group this question sits in, by position in form.groups.
    group_index: number;
    code: string;
    label: string;
    points_possible: number;
    is_bonus: boolean;
    has_scores?: boolean;
    domains: Allocation[];
};

type WireInstrumentData = {
    groups?: WireGroupRow[];
    title: string;
    academic_period_id: number | null;
    instrument_type_id: number | null;
    custom_instrument_type_name: string;
    applied_on: string;
    status: string;
    purpose: string;
    counts_toward_classification: boolean;
    total_points: number | string;
    allow_bonus: boolean;
    items: WireItemRow[];
};

type InstrumentData = Omit<WireInstrumentData, 'items' | 'groups'> & {
    items: ItemRow[];
    // Always present in the form, even for a simple instrument — it holds the
    // implicit group, which is what keeps every question attached to something.
    groups: GroupRow[];
};

type ImportableInstrument = {
    ulid: string;
    title: string;
    class_label: string;
    applied_on: string;
    total_points: number | null;
    allow_bonus: boolean;
    items: WireItemRow[];
};

const props = defineProps<{
    periods: Option[];
    types: Option[];
    domains: Option[];
    initial?: WireInstrumentData;
    submitUrl: string;
    method: 'post' | 'put';
    importableInstruments?: ImportableInstrument[];
    // Only consulted when creating (no `initial`) — a known-valid period id a
    // caller can pass so the teacher does not have to re-pick it. Ignored
    // once editing an existing instrument, whose own period always wins.
    defaultAcademicPeriodId?: number | null;
}>();

function pointsFromPercent(pointsPossible: number, allocationPercent: number): number {
    return Math.round(((Number(pointsPossible) || 0) * (Number(allocationPercent) || 0)) / 100 * 100) / 100;
}

function wireToUiItem(item: WireItemRow): ItemRow {
    return {
        ulid: item.ulid,
        group_index: item.group_index ?? 0,
        code: item.code,
        label: item.label ?? '',
        points_possible: item.points_possible,
        is_bonus: item.is_bonus,
        has_scores: item.has_scores,
        domains: item.domains.map((allocation) => ({
            domain_id: allocation.domain_id,
            points: pointsFromPercent(item.points_possible, allocation.allocation_percent),
        })),
    };
}

const form = useForm<InstrumentData>(
    props.initial
        ? {
              ...props.initial,
              // A backfilled instrument arrives with its single unnamed group.
              // Defaulting to one keeps a brand-new form on the same footing.
              groups: props.initial.groups?.length
                  ? props.initial.groups.map((group) => ({
                        ulid: group.ulid,
                        label: group.label ?? '',
                    }))
                  : [{ label: '' }],
              items: props.initial.items.map(wireToUiItem),
          }
        : {
              groups: [{ label: '' }],
              title: '',
              academic_period_id: props.defaultAcademicPeriodId ?? null,
              instrument_type_id: null,
              custom_instrument_type_name: '',
              applied_on: '',
              status: 'prepared',
              purpose: 'summative',
              counts_toward_classification: true,
              total_points: 100,
              allow_bonus: false,
              items: [
                  {
                      group_index: 0,
                      code: 'Q1',
                      label: '',
                      points_possible: 100,
                      is_bonus: false,
                      domains: [],
                  },
              ],
          },
);

// Tracks an explicit professor decision on "Contabiliza para classificação",
// separately from whatever value the checkbox currently shows — the checkbox
// alone can't tell a suggested default apart from a deliberate choice, since
// both are just a boolean. Only ever set true by the checkbox's own @change
// (a real click), never by the purpose-driven suggestion below, and never
// reset back to false once true — "explicit, once, forever" for this form's
// lifetime, matching the create/edit split already given by `props.initial`.
const countsToggledByUser = ref(false);

// Suggests "Não" the moment the teacher picks "Diagnóstica" on a NEW
// instrument — never on edit (props.initial), and never once the teacher has
// explicitly set the checkbox themselves. One-directional on purpose: picking
// a different purpose afterwards does not try to re-suggest anything, so a
// professor who already saw and accepted (or overrode) the diagnostic
// default never gets silently overwritten again.
function onPurposeChange(): void {
    if (props.initial || countsToggledByUser.value) {
        return;
    }

    if (form.purpose === 'diagnostic') {
        form.counts_toward_classification = false;
    }
}

/**
 * The next automatic question code, scoped to its group.
 *
 * A code identifies a question WITHIN ITS GROUP, so Oralidade and Gramática can
 * each have their own Q1 — and numbering therefore restarts in each group.
 *
 * Highest + 1, not first-gap: a code freed by deleting a question is not handed
 * to a different one later, which keeps identities stable during an edit. Codes
 * that are not strictly Q-followed-by-digits (a hand-typed "Oral-A") are ignored
 * when looking for the highest, and never block the sequence.
 */
function nextItemCode(groupIndex: number): string {
    let highest = 0;

    for (const item of form.items) {
        if (item.group_index !== groupIndex) {
            continue;
        }

        // Case-insensitive on purpose: the column's collation treats "q2" and
        // "Q2" as the same code, so a hand-typed "q2" must still count.
        const match = /^q(\d+)$/i.exec(String(item.code ?? '').trim());

        if (match) {
            highest = Math.max(highest, Number(match[1]));
        }
    }

    return `Q${highest + 1}`;
}

function addItem(groupIndex = 0): void {
    form.items.push({
        group_index: groupIndex,
        code: nextItemCode(groupIndex),
        label: '',
        points_possible: 0,
        is_bonus: false,
        domains: [],
    });
}

function removeItem(index: number): void {
    form.items.splice(index, 1);
}

// ---------------------------------------------------------------- groups

// The teacher is in "groups mode" once any group carries a name. An instrument
// whose only group is unnamed is the ordinary, simple case and shows no
// structure at all — the implicit group exists in the database and nowhere else.
const hasExplicitGroups = computed(() =>
    form.groups.some((group) => group.label.trim() !== ''),
);

const itemsByGroup = computed(() => {
    const indexed = form.items.map((item, index) => ({ item, index }));
    const map = new Map<number, { item: ItemRow; index: number }[]>();

    form.groups.forEach((_group, groupIndex) => {
        map.set(
            groupIndex,
            indexed.filter(({ item }) => item.group_index === groupIndex),
        );
    });

    return map;
});

// Shown discreetly in each group's header. Purely informational — the
// instrument's own total keeps obeying the existing rules.
const groupTotals = computed(() => {
    const totals = new Map<number, number>();

    form.groups.forEach((_group, groupIndex) => {
        const items = itemsByGroup.value.get(groupIndex) ?? [];

        totals.set(
            groupIndex,
            items.reduce(
                (sum, { item }) =>
                    item.is_bonus ? sum : sum + (Number(item.points_possible) || 0),
                0,
            ),
        );
    });

    return totals;
});

/**
 * Turning a simple instrument into a structured one does NOT create a second
 * group and leave the first as "no group": the group that already exists is the
 * one that gets a name. No item is recreated, no ULID changes — the implicit
 * group is simply promoted by being given a label.
 */
function enableGroups(): void {
    if (form.groups.length === 0) {
        form.groups.push({ label: '' });

        return;
    }

    if (form.groups[0].label === '') {
        form.groups[0].label = '';
    }

    // Mark it as explicit so the section becomes visible and focusable even
    // before the teacher types anything.
    groupsRevealed.value = true;
}

// Explicit either because a group is named, or because the teacher just asked
// to organise and has not typed the first name yet.
const groupsRevealed = ref(false);
const groupsVisible = computed(
    () => hasExplicitGroups.value || groupsRevealed.value,
);

function addGroup(): void {
    groupsRevealed.value = true;
    form.groups.push({ label: '' });
}

function canRemoveGroup(groupIndex: number): boolean {
    return (itemsByGroup.value.get(groupIndex) ?? []).length === 0;
}

function removeGroup(groupIndex: number): void {
    if (!canRemoveGroup(groupIndex)) {
        return;
    }

    form.groups.splice(groupIndex, 1);

    // Items below the removed group shift up with it.
    for (const item of form.items) {
        if (item.group_index > groupIndex) {
            item.group_index--;
        }
    }

    if (form.groups.length === 0) {
        form.groups.push({ label: '' });
        groupsRevealed.value = false;
    }
}

function moveGroup(groupIndex: number, direction: -1 | 1): void {
    const target = groupIndex + direction;

    if (target < 0 || target >= form.groups.length) {
        return;
    }

    const [moved] = form.groups.splice(groupIndex, 1);
    form.groups.splice(target, 0, moved);

    // Items follow their group rather than staying at a numeric position.
    for (const item of form.items) {
        if (item.group_index === groupIndex) {
            item.group_index = target;
        } else if (item.group_index === target) {
            item.group_index = groupIndex;
        }
    }
}

/**
 * Moving a question to a group that already holds its code is refused rather
 * than silently renumbered — the teacher decides whether to change the code or
 * leave the question where it is.
 */
const moveError = ref<{ index: number; message: string } | null>(null);

function moveItemToGroup(itemIndex: number, groupIndex: number): void {
    const item = form.items[itemIndex];
    const code = String(item.code ?? '').trim().toLowerCase();

    const clash = form.items.some(
        (other, index) =>
            index !== itemIndex &&
            other.group_index === groupIndex &&
            String(other.code ?? '').trim().toLowerCase() === code,
    );

    if (clash) {
        moveError.value = {
            index: itemIndex,
            message: `Já existe uma questão ${item.code} no grupo ${groupLabelFor(groupIndex)}.`,
        };

        return;
    }

    moveError.value = null;
    item.group_index = groupIndex;
}

function groupLabelFor(groupIndex: number): string {
    const label = (form.groups[groupIndex]?.label ?? '').trim();

    return label === '' ? `Grupo ${groupIndex + 1}` : label;
}

// A code must be unique inside its group — Oralidade/Q1 and Gramática/Q1 are
// two different questions, so only a repeat within one group is a conflict.
const duplicateCodeIndexes = computed(() => {
    const seen = new Map<string, number>();
    const duplicates = new Set<number>();

    form.items.forEach((item, index) => {
        const code = String(item.code ?? '')
            .trim()
            .toLowerCase();

        if (code === '') {
            return;
        }

        const key = `${item.group_index}:${code}`;

        if (seen.has(key)) {
            duplicates.add(seen.get(key)!);
            duplicates.add(index);
        } else {
            seen.set(key, index);
        }
    });

    return duplicates;
});

// A visible group must be named before saving — the form never shows a list of
// "Grupo sem nome". The implicit group is exempt because it is not shown.
const unnamedVisibleGroups = computed(() => {
    if (!groupsVisible.value) {
        return new Set<number>();
    }

    const unnamed = new Set<number>();

    form.groups.forEach((group, index) => {
        if (group.label.trim() === '') {
            unnamed.add(index);
        }
    });

    return unnamed;
});

const canSubmit = computed(
    () => duplicateCodeIndexes.value.size === 0 && unnamedVisibleGroups.value.size === 0,
);

const selectedImportUlid = ref<string | null>(null);

function applyImportedTemplate(): void {
    const source = props.importableInstruments?.find(
        (instrument) => instrument.ulid === selectedImportUlid.value,
    );

    if (!source) {
        return;
    }

    form.title = source.title;
    form.total_points = source.total_points ?? 0;
    form.allow_bonus = source.allow_bonus;
    form.items = source.items.map((item) => {
        const uiItem = wireToUiItem(item);

        return { ...uiItem, ulid: undefined, has_scores: undefined };
    });
}

const selectedDomainIds = ref<number[]>(
    Array.from(
        new Set(
            (props.initial?.items ?? []).flatMap((item) => item.domains.map((allocation) => allocation.domain_id)),
        ),
    ),
);

// Precomputed (not plain functions called inline in v-for) so typing in one
// item doesn't re-run a fresh map/filter over the whole list on every
// keystroke for every domain section on screen.
// A single-domain instrument ("Ficha de Gramática") has nothing to distribute,
// so every question is that domain at 100% and the teacher never has to say so.
// Driven from here rather than from InstrumentDomainAllocations because
// form.items is this component's own state — a child writing into a prop would
// be mutating something it does not own.
watch(
    [() => form.items, selectedDomainIds],
    () => {
        if (selectedDomainIds.value.length !== 1) {
            return;
        }

        const domainId = selectedDomainIds.value[0];

        for (const item of form.items) {
            // Only while the question is untouched or already on that single
            // domain — a question deliberately split across domains keeps what
            // the teacher gave it.
            if (item.domains.length > 1) {
                continue;
            }

            const points = Number(item.points_possible) || 0;

            if (item.domains.length === 0) {
                item.domains.push({ domain_id: domainId, points });

                continue;
            }

            if (item.domains[0].domain_id === domainId) {
                item.domains[0].points = points;
            }
        }
    },
    { deep: true, immediate: true },
);

const itemsTotal = computed(() =>
    form.items.reduce(
        (sum, item) =>
            item.is_bonus ? sum : sum + (Number(item.points_possible) || 0),
        0,
    ),
);

const totalMatches = computed(
    () =>
        form.allow_bonus ||
        Math.abs(itemsTotal.value - Number(form.total_points)) < 0.0001,
);

// Attributed per allocation row, not per item — a question split across two
// domains contributes its own partial points to each, never the item's full
// total to both (an item can't be cleanly filed under a single domain here).
const domainTotals = computed(() => {
    const totals = new Map<number, number>();

    for (const domainId of selectedDomainIds.value) {
        totals.set(domainId, 0);
    }

    for (const item of form.items) {
        for (const allocation of item.domains) {
            if (totals.has(allocation.domain_id)) {
                totals.set(allocation.domain_id, (totals.get(allocation.domain_id) ?? 0) + (Number(allocation.points) || 0));
            }
        }
    }

    return totals;
});

function submit(): void {
    form.transform((data) => ({
        ...data,
        // An empty label means the group was never named — it stays the
        // implicit group, which the server records as NULL.
        groups: data.groups.map((group) => ({
            ...group,
            label: group.label.trim() === '' ? null : group.label.trim(),
        })),
        items: data.items.map((item) => {
            if (item.domains.length === 0) {
                return { ...item, domains: [] };
            }

            const total = item.domains.reduce((sum, allocation) => sum + (Number(allocation.points) || 0), 0);

            return {
                ...item,
                domains: item.domains.map((allocation, index) => {
                    if (total <= 0) {
                        return { domain_id: allocation.domain_id, allocation_percent: 0 };
                    }

                    if (index === item.domains.length - 1) {
                        const othersPercent = item.domains
                            .slice(0, -1)
                            .reduce((sum, other) => sum + ((Number(other.points) || 0) / total) * 100, 0);

                        return { domain_id: allocation.domain_id, allocation_percent: Math.max(0, 100 - othersPercent) };
                    }

                    return {
                        domain_id: allocation.domain_id,
                        allocation_percent: ((Number(allocation.points) || 0) / total) * 100,
                    };
                }),
            };
        }),
    })).submit(props.method, props.submitUrl, { preserveScroll: true });
}
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <div
            v-if="importableInstruments && importableInstruments.length"
            class="flex flex-wrap items-end gap-3 rounded-lg border border-dashed border-border p-3"
        >
            <div class="grid gap-1.5">
                <Label class="text-xs">Importar de outro elemento de avaliação</Label>
                <select
                    v-model="selectedImportUlid"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option :value="null">Nenhum</option>
                    <option
                        v-for="instrument in importableInstruments"
                        :key="instrument.ulid"
                        :value="instrument.ulid"
                    >
                        {{ instrument.title }} · {{ instrument.class_label }} · {{ instrument.applied_on }}
                    </option>
                </select>
            </div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="!selectedImportUlid"
                @click="applyImportedTemplate"
            >
                Importar questões
            </Button>
            <p class="w-full text-xs text-muted-foreground">
                Copia o título, a cotação total e as questões — nunca notas de alunos. Continua tudo editável depois de importar.
            </p>
        </div>

        <section class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2 sm:col-span-2">
                <Label for="title">Designação</Label>
                <Input
                    id="title"
                    v-model="form.title"
                    placeholder="Ex.: Teste de compreensão leitora"
                />
                <InputError :message="form.errors.title" />
            </div>
            <div class="grid gap-2">
                <Label for="instrument_type_id">Tipo</Label>
                <select
                    id="instrument_type_id"
                    v-model.number="form.instrument_type_id"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option :value="null" disabled>Escolher…</option>
                    <option
                        v-for="type in types"
                        :key="type.id"
                        :value="type.id"
                    >
                        {{ type.label }}
                    </option>
                    <option :value="OTHER_TYPE_ID">Outro…</option>
                </select>
                <InputError :message="form.errors.instrument_type_id" />
                <Input
                    v-if="form.instrument_type_id === OTHER_TYPE_ID"
                    v-model="form.custom_instrument_type_name"
                    placeholder="Designação do tipo"
                />
                <InputError :message="form.errors.custom_instrument_type_name" />
            </div>
            <div class="grid gap-2">
                <Label for="academic_period_id">Período</Label>
                <select
                    id="academic_period_id"
                    v-model.number="form.academic_period_id"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option :value="null" disabled>Escolher…</option>
                    <option
                        v-for="period in periods"
                        :key="period.id"
                        :value="period.id"
                    >
                        {{ period.label }}
                    </option>
                </select>
                <InputError :message="form.errors.academic_period_id" />
            </div>
            <div class="grid gap-2">
                <Label for="purpose">Finalidade</Label>
                <select
                    id="purpose"
                    v-model="form.purpose"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    @change="onPurposeChange"
                >
                    <option value="diagnostic">Diagnóstica</option>
                    <option value="formative">Formativa</option>
                    <option value="summative">Sumativa</option>
                    <option value="other">Outra</option>
                </select>
                <InputError :message="form.errors.purpose" />
            </div>
            <div class="grid gap-2">
                <Label for="applied_on">Data</Label>
                <Input id="applied_on" v-model="form.applied_on" type="date" />
                <InputError :message="form.errors.applied_on" />
            </div>
            <div class="grid gap-2">
                <Label for="total_points">Cotação total</Label>
                <Input
                    id="total_points"
                    v-model.number="form.total_points"
                    type="number"
                    min="0"
                    step="1"
                />
                <InputError :message="form.errors.total_points" />
            </div>
            <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input
                    v-model="form.counts_toward_classification"
                    type="checkbox"
                    class="size-4"
                    @change="countsToggledByUser = true"
                />
                Conta para a classificação
                <span class="text-xs text-muted-foreground"
                    >(independente de ser diagnóstico ou sumativo)</span
                >
            </label>
            <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input
                    v-model="form.allow_bonus"
                    type="checkbox"
                    class="size-4"
                />
                Permitir cotações de bónus acima do total
            </label>
        </section>

        <section class="space-y-3">
            <div>
                <h2 class="text-sm font-semibold">Domínios avaliados</h2>
                <p class="text-sm text-muted-foreground">
                    Escolhe os domínios que este elemento de avaliação avalia — depois cria as questões dentro de cada um.
                </p>
            </div>
            <div v-if="domains.length" class="flex flex-wrap gap-4">
                <label
                    v-for="domain in domains"
                    :key="domain.id"
                    class="flex items-center gap-2 text-sm"
                >
                    <input
                        v-model="selectedDomainIds"
                        type="checkbox"
                        :value="domain.id"
                        class="size-4"
                    />
                    {{ domain.label }}
                </label>
            </div>
            <p v-else class="text-xs text-muted-foreground">
                A turma não tem perfil ativo, por isso não há domínios para escolher.
            </p>
        </section>

        <!--
            One section per group. A simple instrument has a single unnamed
            group, whose header is hidden entirely — the teacher sees just
            "Questões" and never learns that a group exists. Sections only
            appear once they organise the instrument themselves.
        -->
        <section
            v-for="(group, groupIndex) in form.groups"
            :key="group.ulid ?? `group-${groupIndex}`"
            class="space-y-3"
            :class="
                groupsVisible
                    ? 'rounded-lg border border-border bg-muted/20 p-3'
                    : undefined
            "
        >
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div v-if="groupsVisible" class="grid min-w-48 flex-1 gap-1.5">
                    <Label :for="`group-label-${groupIndex}`" class="text-xs"
                        >Nome do grupo/secção</Label
                    >
                    <Input
                        :id="`group-label-${groupIndex}`"
                        v-model="group.label"
                        placeholder="Ex.: Oralidade"
                        :aria-invalid="unnamedVisibleGroups.has(groupIndex)"
                        :class="
                            unnamedVisibleGroups.has(groupIndex)
                                ? 'border-destructive'
                                : undefined
                        "
                    />
                    <p
                        v-if="unnamedVisibleGroups.has(groupIndex)"
                        class="text-xs text-destructive"
                    >
                        Dá um nome a este grupo.
                    </p>
                </div>
                <h2 v-else class="text-sm font-semibold">Questões</h2>

                <div class="flex flex-wrap items-center gap-2">
                    <span
                        v-if="groupsVisible"
                        class="text-xs text-muted-foreground tabular-nums"
                    >
                        {{ groupTotals.get(groupIndex) ?? 0 }} pontos
                    </span>
                    <template v-if="groupsVisible && form.groups.length > 1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :disabled="groupIndex === 0"
                            :aria-label="`Mover o grupo ${groupLabelFor(groupIndex)} para cima`"
                            title="Mover para cima"
                            @click="moveGroup(groupIndex, -1)"
                        >
                            <ArrowUp class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :disabled="groupIndex === form.groups.length - 1"
                            :aria-label="`Mover o grupo ${groupLabelFor(groupIndex)} para baixo`"
                            title="Mover para baixo"
                            @click="moveGroup(groupIndex, 1)"
                        >
                            <ArrowDown class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :disabled="!canRemoveGroup(groupIndex)"
                            :aria-label="`Remover o grupo ${groupLabelFor(groupIndex)}`"
                            :title="
                                canRemoveGroup(groupIndex)
                                    ? 'Remover grupo'
                                    : 'Este grupo contém questões. Move ou elimina as questões antes de remover o grupo.'
                            "
                            @click="removeGroup(groupIndex)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </template>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :aria-label="
                            groupsVisible
                                ? `Adicionar questão ao grupo ${groupLabelFor(groupIndex)}`
                                : 'Adicionar questão'
                        "
                        @click="addItem(groupIndex)"
                    >
                        <Plus class="size-4" /> Adicionar questão
                    </Button>
                </div>
            </div>

            <InputError v-if="groupIndex === 0" :message="form.errors.items" />

            <div
                v-for="{ item, index } in itemsByGroup.get(groupIndex) ?? []"
                :key="item.ulid ?? `item-${index}`"
                class="space-y-3 rounded-lg border border-border bg-background p-3"
            >
                <div
                    class="grid items-end gap-3 sm:grid-cols-[6rem_1fr_7rem_auto]"
                >
                    <div class="grid gap-1.5">
                        <Label class="text-xs">Código</Label>
                        <Input
                            v-model="item.code"
                            placeholder="Ex.: Q1"
                            :aria-invalid="duplicateCodeIndexes.has(index)"
                            :class="
                                duplicateCodeIndexes.has(index)
                                    ? 'border-destructive'
                                    : undefined
                            "
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label class="text-xs">Enunciado (opcional)</Label>
                        <Input
                            v-model="item.label"
                            placeholder="Ex.: Compreensão do texto"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label class="text-xs">Cotação</Label>
                        <Input
                            v-model.number="item.points_possible"
                            type="number"
                            min="0"
                            step="0.25"
                        />
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :disabled="form.items.length <= 1 || item.has_scores"
                        :aria-label="`Remover a questão ${item.code}`"
                        :title="
                            item.has_scores
                                ? 'Já tem notas lançadas — limpe as notas primeiro para poder remover.'
                                : 'Remover questão'
                        "
                        @click="removeItem(index)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>

                <p
                    v-if="duplicateCodeIndexes.has(index)"
                    class="text-xs text-destructive"
                >
                    Já existe uma questão com este código neste grupo.
                </p>

                <div
                    v-if="groupsVisible && form.groups.length > 1"
                    class="flex flex-wrap items-center gap-2"
                >
                    <Label :for="`move-${index}`" class="text-xs"
                        >Mover para</Label
                    >
                    <select
                        :id="`move-${index}`"
                        class="h-8 rounded-md border border-border bg-background px-2 text-xs"
                        :value="item.group_index"
                        @change="
                            moveItemToGroup(
                                index,
                                Number(
                                    ($event.target as HTMLSelectElement).value,
                                ),
                            )
                        "
                    >
                        <option
                            v-for="(target, targetIndex) in form.groups"
                            :key="targetIndex"
                            :value="targetIndex"
                        >
                            {{ groupLabelFor(targetIndex) }}
                        </option>
                    </select>
                    <span
                        v-if="moveError && moveError.index === index"
                        class="text-xs text-destructive"
                        >{{ moveError.message }}</span
                    >
                </div>

                <InstrumentDomainAllocations
                    :item="item"
                    :domains="domains"
                    :selected-domain-ids="selectedDomainIds"
                />
            </div>
        </section>

        <div class="flex flex-wrap gap-2">
            <Button
                v-if="!groupsVisible"
                type="button"
                variant="outline"
                size="sm"
                @click="enableGroups"
            >
                Organizar por grupos/secções
            </Button>
            <Button
                v-else
                type="button"
                variant="outline"
                size="sm"
                @click="addGroup"
            >
                <Plus class="size-4" /> Adicionar grupo/secção
            </Button>
        </div>

        <section
            v-if="selectedDomainIds.length"
            class="space-y-2 rounded-lg border border-border p-3"
        >
            <h2 class="text-sm font-semibold">Resumo por domínio</h2>
            <p class="text-xs text-muted-foreground">
                Uma questão com mais do que um domínio contribui aqui só com a parte que lhe cotaste em cada um.
            </p>
            <ul class="space-y-1 text-sm">
                <li
                    v-for="domainId in selectedDomainIds"
                    :key="domainId"
                    class="flex items-center justify-between"
                >
                    <span>{{ domains.find((domain) => domain.id === domainId)?.label }}</span>
                    <span class="font-medium tabular-nums">{{ domainTotals.get(domainId) ?? 0 }} pts</span>
                </li>
            </ul>
        </section>

        <div
            class="flex items-center justify-between rounded-lg border px-4 py-2.5 text-sm"
            :class="
                totalMatches
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-900'
                    : 'border-amber-300 bg-amber-50 text-amber-900'
            "
        >
            <span class="font-medium">Soma das cotações</span>
            <span class="font-semibold tabular-nums">
                {{ itemsTotal
                }}{{ form.total_points ? ` / ${form.total_points}` : '' }}
                {{ totalMatches ? '✓' : '' }}
            </span>
        </div>

        <p
            v-if="!canSubmit"
            class="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
        >
            <template v-if="duplicateCodeIndexes.size > 0">
                Há questões com o mesmo código dentro de um grupo.
            </template>
            <template v-else>
                Há grupos por nomear.
            </template>
        </p>

        <Button type="submit" :disabled="form.processing || !canSubmit">{{
            method === 'post' ? 'Criar elemento de avaliação' : 'Guardar alterações'
        }}</Button>
    </form>
</template>
