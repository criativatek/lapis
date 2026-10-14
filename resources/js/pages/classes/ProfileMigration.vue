<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';

type Cell = {
    period_label: string;
    state: 'none' | 'kept' | 'refreshed';
    /** The normalized percentage, as the engine holds it. */
    before: string | null;
    after: string | null;
    /** Ready to show: the proposal read on the scale of the origin / of the target version. */
    before_text: string | null;
    after_text: string | null;
    changed: boolean;
};
type Row = { name: string; class_number: number | null; cells: Cell[]; changed: boolean };
type Version = {
    version: number;
    name: string;
    scale: { name: string; kind: string } | null;
    domains: { name: string; weight: string }[];
};
type Comparison = {
    scale_changes: boolean;
    domains_same: boolean;
    weights_same: boolean;
    rules_same: boolean;
    domains: { name: string; from_weight: string | null; to_weight: string | null; changed: boolean }[];
};
type Preview = {
    from: Version | null;
    to: Version;
    comparison: Comparison;
    periods: string[];
    rows: Row[];
    affected_enrollment_count: number;
    recalculated_result_count: number;
    kept_result_count: number;
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string };
    toVersionUlid: string;
    preview: Preview;
}>();

const form = useForm({
    to_version: props.toVersionUlid,
    reason: '',
});

// What the engine holds underneath, as a percentage of the maximum. Shown small and
// only where the scale changes, because there the headline text is no longer that
// percentage. "—" for a null (no computable result), never 0.
function normalized(cell: string | null): string {
    return cell === null ? '—' : `${Number(cell).toFixed(1).replace('.', ',')}%`;
}

function weight(value: string | null): string {
    return value === null ? '—' : `${value.replace('.', ',')}%`;
}

// An example taken from this very preview: the first student whose proposal is
// read differently once the class is on the new scale.
const example = computed(() => {
    for (const row of props.preview.rows) {
        for (const cell of row.cells) {
            if (cell.state === 'refreshed' && cell.changed && cell.before_text && cell.after_text && cell.before_text !== '—' && cell.after_text !== '—') {
                return { name: row.name, period: cell.period_label, before: cell.before_text, after: cell.after_text };
            }
        }
    }

    return null;
});

// The sentence about the percentage not moving is only true when everything
// that produces it is the same.
const sameCalculation = computed(
    () => props.preview.comparison.domains_same && props.preview.comparison.weights_same && props.preview.comparison.rules_same,
);

// «1 aluno», «2 alunos» — the header is read as a sentence.
function counted(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

function submit(): void {
    form.post(`/classes/${props.schoolClass.ulid}/profile-migration`);
}
</script>

<template>
    <Head :title="`Migrar perfil — ${schoolClass.label}`" />

    <div class="space-y-4 p-4">
        <div>
            <Heading :title="`Migrar perfil — ${schoolClass.label}`" :description="schoolClass.subject" />
            <Link :href="`/classes/${schoolClass.ulid}`" class="text-sm text-muted-foreground hover:underline">← Voltar à turma</Link>
        </div>

        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-border p-4 text-sm">
            <span class="text-muted-foreground">
                {{ preview.from ? `${preview.from.name} v${preview.from.version}` : 'Sem perfil' }}
            </span>
            <ArrowRight class="size-4 text-muted-foreground" />
            <span class="font-medium">{{ preview.to.name }} v{{ preview.to.version }}</span>
            <span class="ml-auto text-muted-foreground">
                {{ counted(preview.affected_enrollment_count, 'aluno com a proposta alterada', 'alunos com a proposta alterada') }} ·
                {{ counted(preview.recalculated_result_count, 'proposta recalculada', 'propostas recalculadas') }} ·
                {{ counted(preview.kept_result_count, 'decisão mantida', 'decisões mantidas') }}
            </span>
        </div>

        <!-- O QUE MUDA ENTRE AS DUAS VERSÕES, dito por extenso: a escala, os
             domínios e os pesos, e as regras de cálculo. Comparar só os
             números escondia a mudança quando era a escala que mudava. -->
        <section class="space-y-3 rounded-lg border border-border p-4 text-sm" aria-label="Comparação entre as versões">
            <p>
                <span class="font-medium">Escala:</span>{{ ' ' }}
                <span
                    v-if="preview.comparison.scale_changes"
                    class="ml-1 inline-flex items-center gap-1 rounded bg-amber-100 px-2 py-0.5 font-semibold text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                >
                    <TriangleAlert class="size-3" aria-hidden="true" />
                    {{ preview.from?.scale?.name ?? '—' }} → {{ preview.to.scale?.name ?? '—' }}
                </span>
                <span v-else class="ml-1 text-muted-foreground">sem alteração ({{ preview.to.scale?.name ?? '—' }})</span>
            </p>

            <div class="overflow-x-auto">
                <table class="w-full max-w-xl text-sm">
                    <caption class="sr-only">
                        Domínios e pesos de cada versão
                    </caption>
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1 pr-3 font-medium">Domínio</th>
                            <th class="px-3 py-1 text-right font-medium">Peso na origem</th>
                            <th class="px-3 py-1 text-right font-medium">Peso no destino</th>
                            <th class="py-1 pl-3 font-medium"><span class="sr-only">Estado</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="domain in preview.comparison.domains" :key="domain.name">
                            <td class="py-1 pr-3">{{ domain.name }}</td>
                            <td class="px-3 py-1 text-right tabular-nums text-muted-foreground">{{ weight(domain.from_weight) }}</td>
                            <td class="px-3 py-1 text-right tabular-nums">{{ weight(domain.to_weight) }}</td>
                            <td class="py-1 pl-3 text-xs" :class="domain.changed ? 'font-semibold text-amber-700 dark:text-amber-400' : 'text-muted-foreground'">
                                {{ domain.changed ? 'alterado' : 'iguais' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p>
                <span class="font-medium">Regras de cálculo:</span>{{ ' ' }}
                <span class="ml-1" :class="preview.comparison.rules_same ? 'text-muted-foreground' : 'font-semibold text-amber-700 dark:text-amber-400'">
                    {{ preview.comparison.rules_same ? 'iguais' : 'alteradas' }}
                </span>
            </p>

            <div
                v-if="preview.comparison.scale_changes"
                class="space-y-1 rounded-md bg-muted/40 px-3 py-2 text-muted-foreground"
                data-testid="scale-change-explanation"
            >
                <p v-if="sameCalculation">
                    O resultado em percentagem de cada aluno não muda. Muda a proposta, que passa a ser lida na nova escala: o nível da banda em
                    que esse resultado cai.
                </p>
                <p v-else>A proposta de cada aluno passa a ser lida na nova escala: o nível da banda em que o resultado calculado cai.</p>
                <p v-if="example">
                    Por exemplo, a proposta de {{ example.name }} ({{ example.period }}), hoje lida como {{ example.before }}, passa a ser lida como
                    {{ example.after }}.
                </p>
                <p>As decisões já confirmadas ou publicadas ficam como foram tomadas, na escala de origem — não são convertidas nem recalculadas.</p>
            </div>
        </section>

        <p class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            A migração muda a versão de perfil da turma e recalcula as propostas em aberto. As classificações já
            confirmadas ou publicadas mantêm-se como estão, na versão que as produziu — o histórico não é recalculado.
        </p>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium" rowspan="2">Aluno</th>
                        <th v-for="label in preview.periods" :key="label" class="border-l border-border px-3 py-2 text-center font-medium" colspan="2">
                            {{ label }}
                        </th>
                    </tr>
                    <tr class="text-xs text-muted-foreground">
                        <template v-for="label in preview.periods" :key="`h-${label}`">
                            <th class="border-l border-border px-3 py-1 text-right font-normal">Antes</th>
                            <th class="px-3 py-1 text-right font-normal">Depois</th>
                        </template>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="(row, rowIndex) in preview.rows" :key="rowIndex" class="hover:bg-muted/20" :class="{ 'bg-amber-50/40 dark:bg-amber-950/20': row.changed }">
                        <td class="px-3 py-2 whitespace-nowrap">
                            <span class="text-muted-foreground">{{ row.class_number ?? '—' }}</span>
                            <span class="ml-2 font-medium">{{ row.name }}</span>
                        </td>
                        <template v-for="(cell, index) in row.cells" :key="index">
                            <td class="border-l border-border px-3 py-2 text-right tabular-nums text-muted-foreground">
                                {{ cell.state === 'none' ? '—' : (cell.before_text ?? '—') }}
                                <span v-if="cell.state === 'refreshed' && preview.comparison.scale_changes" class="block text-[11px]">
                                    resultado {{ normalized(cell.before) }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                <span v-if="cell.state === 'none'" class="text-muted-foreground">—</span>
                                <span v-else-if="cell.state === 'kept'" class="text-xs text-muted-foreground" title="Decisão confirmada ou publicada — mantida como foi tomada">
                                    mantida
                                </span>
                                <span v-else-if="cell.changed" class="inline-flex flex-col items-end">
                                    <span class="inline-flex items-center gap-1 font-semibold text-amber-700 dark:text-amber-400">
                                        {{ cell.after_text ?? '—' }}
                                        <TriangleAlert class="size-3" aria-hidden="true" />
                                        <span class="text-xs font-normal">alterado</span>
                                    </span>
                                    <span v-if="preview.comparison.scale_changes" class="text-[11px] font-normal text-muted-foreground">
                                        resultado {{ normalized(cell.after) }}
                                    </span>
                                </span>
                                <span v-else class="text-muted-foreground">
                                    {{ cell.after_text ?? '—' }}
                                </span>
                            </td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>

        <form class="space-y-3 rounded-lg border border-border p-4" @submit.prevent="submit">
            <label class="block text-sm">
                <span class="mb-1 block font-medium">Motivo da migração (obrigatório)</span>
                <input
                    v-model="form.reason"
                    type="text"
                    maxlength="500"
                    class="w-full rounded-md border border-border bg-background px-3 py-2"
                    placeholder="Ex.: correção da ponderação de Escrita aprovada em conselho de turma"
                    :aria-describedby="form.errors.reason ? 'migration-reason-error' : undefined"
                />
            </label>
            <p v-if="form.errors.reason" id="migration-reason-error" class="text-xs text-red-600">{{ form.errors.reason }}</p>
            <div class="flex gap-2">
                <Link
                    :href="`/classes/${schoolClass.ulid}`"
                    class="rounded-md border border-border px-4 py-3 text-sm hover:bg-muted/40"
                >
                    Cancelar
                </Link>
                <button
                    type="submit"
                    class="rounded-md bg-primary px-4 py-3 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Confirmar migração
                </button>
            </div>
        </form>
    </div>
</template>
