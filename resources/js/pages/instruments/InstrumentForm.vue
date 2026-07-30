<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { id: number; label: string; default_purpose?: string };

type ItemRow = {
    ulid?: string;
    code: string;
    label: string;
    points_possible: number;
    is_bonus: boolean;
    has_scores?: boolean;
    domains: { domain_id: number; allocation_percent: number }[];
};

type InstrumentData = {
    title: string;
    academic_period_id: number | null;
    instrument_type_id: number | null;
    applied_on: string;
    status: string;
    purpose: string;
    counts_toward_classification: boolean;
    total_points: number | string;
    allow_bonus: boolean;
    items: ItemRow[];
};

type ImportableInstrument = {
    ulid: string;
    title: string;
    class_label: string;
    applied_on: string;
    total_points: number | null;
    allow_bonus: boolean;
    items: ItemRow[];
};

const props = defineProps<{
    periods: Option[];
    types: Option[];
    domains: Option[];
    initial?: InstrumentData;
    submitUrl: string;
    method: 'post' | 'put';
    importableInstruments?: ImportableInstrument[];
}>();

const form = useForm<InstrumentData>(
    props.initial ?? {
        title: '',
        academic_period_id: null,
        instrument_type_id: null,
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
    form.items = source.items.map((item) => ({
        code: item.code,
        label: item.label ?? '',
        points_possible: item.points_possible,
        is_bonus: item.is_bonus,
        domains: item.domains.map((domain) => ({ ...domain })),
    }));
}

function addAllocation(item: ItemRow): void {
    item.domains.push({
        domain_id: props.domains[0]?.id ?? 0,
        allocation_percent: 100,
    });
}

function removeAllocation(item: ItemRow, index: number): void {
    item.domains.splice(index, 1);
}

function allocationTotal(item: ItemRow): number {
    return item.domains.reduce(
        (sum, allocation) => sum + (Number(allocation.allocation_percent) || 0),
        0,
    );
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

function submit(): void {
    form.submit(props.method, props.submitUrl, { preserveScroll: true });
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
                </select>
                <InputError :message="form.errors.instrument_type_id" />
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
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-semibold">Questões</h2>
                    <p class="text-sm text-muted-foreground">
                        A distribuição por domínios de cada questão tem de somar
                        100% — ou ficar vazia.
                    </p>
                </div>
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
                v-for="(item, index) in form.items"
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

                <div
                    v-if="domains.length"
                    class="space-y-2 border-t border-border pt-3"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-muted-foreground">
                            Domínios
                            <template v-if="item.domains.length"
                                >— total {{ allocationTotal(item) }}%</template
                            >
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="addAllocation(item)"
                        >
                            <Plus class="size-3.5" /> Domínio
                        </Button>
                    </div>
                    <div
                        v-for="(allocation, allocationIndex) in item.domains"
                        :key="allocationIndex"
                        class="flex items-center gap-2"
                    >
                        <select
                            v-model.number="allocation.domain_id"
                            class="h-8 flex-1 rounded-md border border-input bg-transparent px-2 text-sm"
                        >
                            <option
                                v-for="domain in domains"
                                :key="domain.id"
                                :value="domain.id"
                            >
                                {{ domain.label }}
                            </option>
                        </select>
                        <Input
                            v-model.number="allocation.allocation_percent"
                            type="number"
                            min="0"
                            max="100"
                            class="h-8 w-20"
                        />
                        <span class="text-sm text-muted-foreground">%</span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            @click="removeAllocation(item, allocationIndex)"
                        >
                            <Trash2 class="size-3.5" />
                        </Button>
                    </div>
                    <p
                        v-if="
                            item.domains.length &&
                            Math.abs(allocationTotal(item) - 100) > 0.0001
                        "
                        class="text-xs text-amber-700"
                    >
                        Tem de somar 100%.
                    </p>
                </div>
                <p v-else class="text-xs text-muted-foreground">
                    A turma não tem perfil ativo, por isso não há domínios para
                    distribuir.
                </p>
            </div>

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
        </section>

        <Button type="submit" :disabled="form.processing">{{
            method === 'post' ? 'Criar instrumento' : 'Guardar alterações'
        }}</Button>
    </form>
</template>
