<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import DataTable from '@/components/analysis/DataTable.vue';
import LabelledBarChart from '@/components/analysis/LabelledBarChart.vue';
import TableShell from '@/components/TableShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { qualitativeToneClasses, qualitativeToneFor } from '@/lib/qualitativeTone';
import type { Analysis, Cell, ResultsAnalysisProps } from '@/types/resultsAnalysis';

/**
 * Relatório imprimível. Só o conteúdo do documento é mostrado — o botão
 * «Imprimir» e o link de regresso ficam escondidos na impressão
 * (`print:hidden`). Quando `include_individual` é falso, `students` chega
 * vazio do servidor (§Anexo A): nada aqui reconstrói nomes ou valores
 * individuais a partir de outra fonte.
 */

const props = defineProps<ResultsAnalysisProps>();

function pct(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return `${Number(value).toFixed(1).replace('.', ',')} %`;
}

function toneClassFor(band: Cell['band']): string {
    if (band === null) {
        return qualitativeToneClasses.neutral;
    }

    return qualitativeToneClasses[qualitativeToneFor(band, props.context.scale.bands)];
}

const appliedOnFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC',
});

const appliedOnLabel = computed(() =>
    appliedOnFormatter.format(new Date(`${props.context.instrument.applied_on}T00:00:00Z`)),
);

function quantitativeCategories(analysis: Analysis) {
    return analysis.quantitative.classes.map((row) => ({
        key: row.key,
        label: row.label,
        count: row.count,
        percent: row.percent,
        tone: row.below_threshold ? ('red' as const) : ('neutral' as const),
        emphasis: row.below_threshold ? ('below' as const) : null,
    }));
}

function quantitativeRows(analysis: Analysis) {
    return analysis.quantitative.classes.map((row) => ({
        key: row.key,
        label: row.label,
        count: row.count,
        percent: row.percent,
    }));
}

function qualitativeCategories(analysis: Analysis) {
    const rows = analysis.qualitative.categories.map((category) => ({
        key: category.key,
        label: category.label,
        count: category.count,
        percent: category.percent,
        tone: qualitativeToneFor(category, props.context.scale.bands),
        emphasis: category.is_negative ? ('below' as const) : null,
    }));

    if (analysis.qualitative.unplaced > 0) {
        rows.push({ key: 'unplaced', label: 'Sem apreciação', count: analysis.qualitative.unplaced, percent: null, tone: 'neutral' as const, emphasis: null });
    }

    return rows;
}

function qualitativeRows(analysis: Analysis) {
    const rows = analysis.qualitative.categories.map((category) => ({
        key: category.key,
        label: category.label,
        count: category.count,
        percent: category.percent,
    }));

    if (analysis.qualitative.unplaced > 0) {
        rows.push({ key: 'unplaced', label: 'Sem apreciação', count: analysis.qualitative.unplaced, percent: null });
    }

    return rows;
}

const orderedStudents = computed(() => {
    const inScope = props.students.filter((student) => student.status !== 'out_of_scope');
    const outOfScope = props.students.filter((student) => student.status === 'out_of_scope');

    return [...inScope, ...outOfScope];
});

function printPage(): void {
    window.print();
}
</script>

<template>
    <Head :title="`${report.title} — ${context.instrument.title}`" />

    <div class="mx-auto max-w-4xl space-y-6 p-6 print:p-0">
        <div class="flex items-center justify-between print:hidden">
            <a :href="links.results" class="text-sm text-muted-foreground hover:underline">← Voltar a Resultados</a>
            <Button type="button" size="sm" @click="printPage">Imprimir</Button>
        </div>

        <header class="space-y-1 border-b border-border pb-4">
            <h1 class="text-xl font-semibold">{{ report.title }}</h1>
            <p class="text-sm text-muted-foreground">
                {{ context.instrument.title }} · {{ context.class.label }} · {{ context.period.label }} · {{ appliedOnLabel }}
            </p>
            <p class="text-xs text-muted-foreground">
                {{ context.instrument.type ?? 'Sem tipo' }} · {{ context.instrument.purpose_label }} · gerado em
                {{ new Intl.DateTimeFormat('pt-PT', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(report.generated_at)) }}
            </p>
        </header>

        <p
            v-if="!include_individual"
            class="rounded-md border border-border bg-muted/20 px-4 py-2 text-sm text-muted-foreground"
        >
            Relatório agregado: não inclui nomes nem classificações individuais.
        </p>

        <div v-if="context.is_diagnostic" class="rounded-md border border-sky-300 bg-sky-50 px-4 py-2 text-sm text-sky-950">
            Avaliação diagnóstica — não contribui para médias classificativas.
        </div>

        <!-- Indicadores e distribuição: Global e cada domínio -->
        <section v-for="dimension in dimensions" :key="dimension.key" class="space-y-3 print:break-inside-avoid">
            <h2 class="text-base font-semibold">{{ dimension.key === 'global' ? 'Global' : dimension.label }}</h2>

            <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                <div><span class="text-muted-foreground">Avaliados:</span> {{ dimension.analysis.classified }} de {{ dimension.analysis.universe }}</div>
                <div><span class="text-muted-foreground">Média:</span> {{ pct(dimension.analysis.mean) }}</div>
                <div><span class="text-muted-foreground">Mediana:</span> {{ pct(dimension.analysis.median) }}</div>
                <div>
                    <span class="text-muted-foreground">&lt; {{ context.threshold.label }}:</span>
                    {{ dimension.analysis.threshold.below.count }} ({{ pct(dimension.analysis.threshold.below.percent) }})
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <LabelledBarChart
                        :title="`Distribuição quantitativa — ${dimension.key === 'global' ? 'Classificação global' : dimension.label}`"
                        :total="dimension.analysis.quantitative.total"
                        :categories="quantitativeCategories(dimension.analysis)"
                    />
                    <DataTable caption="Classe" :rows="quantitativeRows(dimension.analysis)" :total="dimension.analysis.quantitative.total" />
                </div>
                <div class="space-y-2">
                    <template v-if="dimension.analysis.qualitative.available">
                        <LabelledBarChart
                            :title="`Distribuição qualitativa — ${dimension.key === 'global' ? 'Classificação global' : dimension.label}`"
                            :total="dimension.analysis.qualitative.total"
                            :categories="qualitativeCategories(dimension.analysis)"
                        />
                        <DataTable caption="Apreciação" :rows="qualitativeRows(dimension.analysis)" :total="dimension.analysis.qualitative.total" />
                    </template>
                    <p v-else class="text-sm text-muted-foreground">
                        A escala configurada para esta turma não define apreciações qualitativas.
                    </p>
                </div>
            </div>
        </section>

        <!-- Secções do relatório -->
        <article v-for="section in report.sections" :key="section.key" class="space-y-2 print:break-inside-avoid">
            <h3 class="text-sm font-semibold">{{ section.title }}</h3>
            <p v-for="(paragraph, index) in section.paragraphs" :key="index" class="text-sm text-muted-foreground">
                {{ paragraph }}
            </p>
            <TableShell v-if="section.table">
                <template #head>
                    <tr>
                        <th v-for="(column, index) in section.table.columns" :key="index" scope="col" class="px-3 py-2 text-left font-medium">
                            {{ column }}
                        </th>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="(row, rowIndex) in section.table.rows" :key="rowIndex">
                        <td v-for="(value, colIndex) in row" :key="colIndex" class="px-3 py-1.5">{{ value }}</td>
                    </tr>
                </template>
            </TableShell>
        </article>

        <!-- Observações do professor (só leitura) -->
        <section class="space-y-1 print:break-inside-avoid">
            <h3 class="text-sm font-semibold">Observações do professor</h3>
            <p v-if="note.body" class="whitespace-pre-line text-sm text-muted-foreground">{{ note.body }}</p>
            <p v-else class="text-sm text-muted-foreground">Sem observações registadas.</p>
        </section>

        <!-- Resultados individuais — só quando include_individual -->
        <section v-if="include_individual" class="space-y-3 print:break-inside-avoid">
            <h2 class="text-base font-semibold">Resultados individuais</h2>
            <TableShell>
                <template #head>
                    <tr>
                        <th scope="col" class="px-3 py-2 font-medium">Nº</th>
                        <th scope="col" class="px-3 py-2 text-left font-medium">Aluno</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">Classificação global</th>
                        <th scope="col" class="px-3 py-2 text-left font-medium">Apreciação</th>
                        <th
                            v-for="domain in context.domains"
                            :key="domain.key"
                            scope="col"
                            class="px-3 py-2 text-left font-medium"
                        >
                            {{ domain.name }}
                        </th>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="student in orderedStudents" :key="student.enrollment_id">
                        <td class="px-3 py-1.5">{{ student.class_number ?? '—' }}</td>
                        <td class="px-3 py-1.5 font-medium">{{ student.name }}</td>
                        <template v-if="student.status === 'out_of_scope'">
                            <td class="px-3 py-1.5 text-right">Não abrangido</td>
                            <td class="px-3 py-1.5" :colspan="1 + context.domains.length">—</td>
                        </template>
                        <template v-else-if="student.global.value === null">
                            <td class="px-3 py-1.5 text-right">{{ student.status_label }}</td>
                            <td class="px-3 py-1.5" :colspan="1 + context.domains.length">—</td>
                        </template>
                        <template v-else>
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ pct(student.global.value) }}</td>
                            <td class="px-3 py-1.5">
                                <Badge v-if="student.global.band" :class="toneClassFor(student.global.band)" class="text-[10px]">
                                    {{ student.global.band.label }}
                                </Badge>
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </td>
                            <td v-for="domain in context.domains" :key="domain.key" class="px-3 py-1.5">
                                <template v-if="student.domains[domain.key] && student.domains[domain.key]!.value !== null">
                                    {{ pct(student.domains[domain.key]!.value) }}
                                </template>
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </td>
                        </template>
                    </tr>
                </template>
            </TableShell>
        </section>
    </div>
</template>

<style scoped>
@media print {
    :global(body) {
        background: #fff;
    }
}
</style>
