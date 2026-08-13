<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
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
    code: string;
    label: string;
    points_possible: number;
    is_bonus: boolean;
    has_scores?: boolean;
    domains: WireAllocation[];
};

// The shape this form edits: the teacher types points per domain directly,
// never a percentage — allocation_percent is derived only at submit time, so
// the calculation engine and schema never need to know points were the input.
type Allocation = { domain_id: number; points: number };
type ItemRow = {
    ulid?: string;
    code: string;
    label: string;
    points_possible: number;
    is_bonus: boolean;
    has_scores?: boolean;
    domains: Allocation[];
};

type WireInstrumentData = {
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

type InstrumentData = Omit<WireInstrumentData, 'items'> & { items: ItemRow[] };

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
        ? { ...props.initial, items: props.initial.items.map(wireToUiItem) }
        : {
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

function addItem(): void {
    form.items.push({
        code: `Q${form.items.length + 1}`,
        label: '',
        points_possible: 0,
        is_bonus: false,
        domains: [],
    });
}

function removeItem(index: number): void {
    form.items.splice(index, 1);
}

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
const itemsByDomain = computed(() => {
    const indexed = form.items.map((item, index) => ({ item, index }));
    const map = new Map<number, { item: ItemRow; index: number }[]>();

    for (const domainId of selectedDomainIds.value) {
        map.set(
            domainId,
            indexed.filter(({ item }) => item.domains.some((allocation) => allocation.domain_id === domainId)),
        );
    }

    return map;
});

const itemsWithNoDomain = computed(() =>
    form.items
        .map((item, index) => ({ item, index }))
        .filter(({ item }) => item.domains.length === 0),
);

function addItemToDomain(domainId: number): void {
    form.items.push({
        code: `Q${form.items.length + 1}`,
        label: '',
        points_possible: 0,
        is_bonus: false,
        domains: [{ domain_id: domainId, points: 0 }],
    });
}

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
                <Label class="text-xs">Importar de outro instrumento</Label>
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
                    placeholder="Teste de Compreensão Leitora"
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
                    Escolhe os domínios que este instrumento avalia — depois cria as questões dentro de cada um.
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

        <section
            v-for="domainId in selectedDomainIds"
            :key="domainId"
            class="space-y-3"
        >
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold">
                    {{ domains.find((domain) => domain.id === domainId)?.label }}
                </h2>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addItemToDomain(domainId)"
                >
                    <Plus class="size-4" /> Adicionar questão
                </Button>
            </div>
            <div
                v-for="{ item, index } in itemsByDomain.get(domainId) ?? []"
                :key="item.ulid ?? index"
                class="space-y-3 rounded-lg border border-border p-3"
            >
                <div
                    class="grid items-end gap-3 sm:grid-cols-[6rem_1fr_7rem_auto]"
                >
                    <div class="grid gap-1.5">
                        <Label class="text-xs">Código</Label>
                        <Input v-model="item.code" placeholder="Q1" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label class="text-xs">Enunciado (opcional)</Label>
                        <Input
                            v-model="item.label"
                            placeholder="Compreensão do texto"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label class="text-xs">Cotação total</Label>
                        <p class="flex h-9 items-center text-sm font-semibold tabular-nums">
                            {{ item.points_possible }} pts
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :disabled="form.items.length <= 1 || item.has_scores"
                        :title="
                            item.has_scores
                                ? 'Já tem notas lançadas — limpe as notas primeiro para poder remover.'
                                : undefined
                        "
                        @click="removeItem(index)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>

                <InstrumentDomainAllocations
                    :item="item"
                    :domains="domains"
                    :selected-domain-ids="selectedDomainIds"
                />
            </div>
        </section>

        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold">Sem domínio associado</h2>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addItem"
                >
                    <Plus class="size-4" /> Adicionar questão
                </Button>
            </div>
            <InputError :message="form.errors.items" />

            <div
                v-for="{ item, index } in itemsWithNoDomain"
                :key="item.ulid ?? index"
                class="space-y-3 rounded-lg border border-border p-3"
            >
                <div
                    class="grid items-end gap-3 sm:grid-cols-[6rem_1fr_7rem_auto]"
                >
                    <div class="grid gap-1.5">
                        <Label class="text-xs">Código</Label>
                        <Input v-model="item.code" placeholder="Q1" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label class="text-xs">Enunciado (opcional)</Label>
                        <Input
                            v-model="item.label"
                            placeholder="Compreensão do texto"
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
                        :title="
                            item.has_scores
                                ? 'Já tem notas lançadas — limpe as notas primeiro para poder remover.'
                                : undefined
                        "
                        @click="removeItem(index)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>

                <InstrumentDomainAllocations
                    :item="item"
                    :domains="domains"
                    :selected-domain-ids="selectedDomainIds"
                />
            </div>
        </section>

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

        <Button type="submit" :disabled="form.processing">{{
            method === 'post' ? 'Criar instrumento' : 'Guardar alterações'
        }}</Button>
    </form>
</template>
