<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FileDown, Printer } from '@lucide/vue';
import { computed, nextTick } from 'vue';
import PrintableDocument from '@/components/documents/PrintableDocument.vue';
import Heading from '@/components/Heading.vue';
import ReportLetterhead from '@/components/reports/ReportLetterhead.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import NativeSelect from '@/components/ui/NativeSelect.vue';

type Orientation = 'auto' | 'portrait' | 'landscape';

type Cell = { text: string; detail: string | null; state: string | null; negative: boolean | null };

type Column = {
    kind: 'item' | 'raw' | 'result' | 'level' | 'domain';
    /** Only a question has a code and a group. */
    code?: string;
    group?: string | null;
    label?: string;
    quota: string | null;
    bonus?: boolean;
};

type Block = {
    caption: string | null;
    columns: Column[];
    group_spans: { label: string | null; span: number }[] | null;
    rows: { number: string; name: string; cells: Cell[] }[];
};

type Bar = { label: string; value_text: string; detail: string | null; width: string | null; negative: boolean };

type Chart = { title: string; basis: string; bars: Bar[]; caution: string | null; empty_message: string };

type GridDocument = {
    title: string;
    kind_label: string;
    subtitle: string;
    identity: {
        name: string;
        header_lines: string[];
        footer_note: string | null;
        is_configured: boolean;
        logo_url: string | null;
    };
    issued_label: string;
    meta: { class: string; subject: string; period: string; applied_on: string; type: string | null; purpose: string; status: string };
    is_diagnostic: boolean;
    state: { is_provisional: boolean; label: string };
    options: { raw_score: boolean; result: boolean; domains: boolean; legend: boolean; charts: boolean; orientation: Orientation };
    charts_available: boolean;
    charts: {
        distribution: Chart & { n_label: string; missing: { label: string; count: number }[]; missing_note: string | null };
        means: Chart & { axis: string };
        questions: Chart & { axis: string; note: string };
    } | null;
    orientation: 'portrait' | 'landscape';
    blocks: Block[];
    summary: { students: number; excluded: number; questions: number; blocks: number; domains: number };
    excluded_note: string | null;
    legend: {
        questions:
            | { code: string; group: string | null; label: string; quota: string; bonus: boolean; domains: string[] }[]
            | null;
        states: { short: string; label: string }[];
    };
    notes: string[];
    notice: string;
    is_empty: boolean;
    empty_message: string;
};

const props = defineProps<{
    instrument: { ulid: string; title: string; class_ulid: string };
    document: GridDocument;
}>();

const baseUrl = computed(() => `/instruments/${props.instrument.ulid}/impressao`);

const orientationQuery: Record<Orientation, string> = { auto: 'auto', portrait: 'vertical', landscape: 'horizontal' };

/** The options as the query string the server reads (and normalises). */
function query(options: GridDocument['options']): Record<string, string> {
    return {
        bruta: options.raw_score ? '1' : '0',
        resultado: options.result ? '1' : '0',
        dominios: options.domains ? '1' : '0',
        legenda: options.legend ? '1' : '0',
        graficos: options.charts ? '1' : '0',
        orientacao: orientationQuery[options.orientation],
    };
}

// Only the options change the page; the document the server sends back is the
// whole answer, and nothing here recomputes a figure.
function setOption(key: 'raw_score' | 'result' | 'domains' | 'legend' | 'charts', checked: boolean | 'indeterminate'): void {
    if (typeof checked !== 'boolean') {
        return;
    }

    router.get(baseUrl.value, query({ ...props.document.options, [key]: checked }), {
        replace: true,
        preserveScroll: true,
        preserveState: true,
    });
}

function setOrientation(value: string | number | null): void {
    if (value !== 'auto' && value !== 'portrait' && value !== 'landscape') {
        return;
    }

    router.get(baseUrl.value, query({ ...props.document.options, orientation: value }), {
        replace: true,
        preserveScroll: true,
        preserveState: true,
    });
}

const pdfHref = computed(() => `${baseUrl.value}/pdf?${new URLSearchParams(query(props.document.options)).toString()}`);

// With a single domain the domain IS the global result: there is nothing to choose.
const hasSeveralDomains = computed(() => props.document.summary.domains >= 2);

// The three charts, in the order they are drawn. The server composed every text and measured every bar.
const chartList = computed(() => {
    const charts = props.document.charts;

    return charts ? [charts.distribution, charts.means, charts.questions] : [];
});

const hasNamedGroups = computed(() => props.document.legend.questions?.some((question) => question.group) ?? false);

function headerOf(column: Column): string {
    return column.kind === 'item' ? (column.code ?? '') : (column.label ?? '');
}

function hasQuotas(block: Block): boolean {
    return block.columns.some((column) => column.quota !== null);
}

// What prints is the preview itself (see PrintableDocument).
function print(): void {
    void nextTick(() => window.print());
}
</script>

<template>
    <Head :title="`${document.title} — ${document.kind_label}`" />

    <div class="mx-auto w-full max-w-7xl space-y-6 p-4">
        <div class="print:hidden">
            <Link
                :href="`/instruments/${instrument.ulid}`"
                class="inline-flex min-h-11 items-center text-sm text-muted-foreground hover:underline sm:min-h-0"
            >
                ← Voltar à grelha de correção
            </Link>
        </div>

        <Heading
            class="print:hidden"
            :title="`${document.kind_label} — imprimir`"
            :description="`${document.title} · ${document.meta.class}`"
        />

        <!-- Controls: none of them changes a number, only what is drawn. -->
        <div class="space-y-4 print:hidden">
            <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
                <label class="flex min-h-11 items-center gap-2 text-sm">
                    <Checkbox
                        id="grid-doc-raw"
                        :model-value="document.options.raw_score"
                        @update:model-value="(value) => setOption('raw_score', value)"
                    />
                    Pontuação bruta
                </label>
                <label class="flex min-h-11 items-center gap-2 text-sm">
                    <Checkbox
                        id="grid-doc-result"
                        :model-value="document.options.result"
                        @update:model-value="(value) => setOption('result', value)"
                    />
                    Resultado e nível
                </label>
                <label class="flex min-h-11 items-center gap-2 text-sm" :class="{ 'opacity-60': !hasSeveralDomains }">
                    <Checkbox
                        id="grid-doc-domains"
                        :model-value="document.options.domains && hasSeveralDomains"
                        :disabled="!hasSeveralDomains"
                        @update:model-value="(value) => setOption('domains', value)"
                    />
                    Resultados por domínio
                    <span v-if="!hasSeveralDomains" class="text-xs text-muted-foreground">
                        (só quando o elemento toca dois ou mais domínios)
                    </span>
                </label>
                <label class="flex min-h-11 items-center gap-2 text-sm">
                    <Checkbox
                        id="grid-doc-legend"
                        :model-value="document.options.legend"
                        @update:model-value="(value) => setOption('legend', value)"
                    />
                    Legenda das questões
                </label>
                <!-- Statistics are official only for a concluded correction. -->
                <label class="flex min-h-11 items-center gap-2 text-sm" :class="{ 'opacity-60': !document.charts_available }">
                    <Checkbox
                        id="grid-doc-charts"
                        :model-value="document.options.charts"
                        :disabled="!document.charts_available"
                        @update:model-value="(value) => setOption('charts', value)"
                    />
                    Gráficos
                    <span v-if="!document.charts_available" class="text-xs text-muted-foreground">
                        (só com a correção concluída)
                    </span>
                </label>

                <label class="grid gap-1 text-sm">
                    <span class="text-xs font-medium text-muted-foreground">Orientação</span>
                    <NativeSelect
                        id="grid-doc-orientation"
                        class="min-h-11 sm:min-h-9"
                        :model-value="document.options.orientation"
                        @update:model-value="setOrientation"
                    >
                        <option value="auto">Automática</option>
                        <option value="portrait">Vertical</option>
                        <option value="landscape">Horizontal</option>
                    </NativeSelect>
                </label>

                <div class="flex items-center gap-1">
                    <Button variant="outline" class="min-h-11" @click="print">
                        <Printer class="size-4" />
                        Imprimir
                    </Button>
                    <!-- A plain link, not an Inertia visit: a binary response cannot
                         come back through one. It carries the SAME options the
                         preview is showing, so the PDF is that sheet. -->
                    <Button variant="outline" class="min-h-11" as-child>
                        <a :href="pdfHref">
                            <FileDown class="size-4" />
                            PDF
                        </a>
                    </Button>
                </div>
            </div>

            <p class="text-xs text-muted-foreground">
                Gerado na hora, a partir dos registos atuais. Não fica guardado como relatório. Orientação aplicada:
                {{ document.orientation === 'landscape' ? 'horizontal' : 'vertical' }}.
            </p>

            <div
                v-if="document.state.is_provisional"
                class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
                role="status"
            >
                <p class="font-medium">Valores provisórios.</p>
                <p class="mt-1">
                    A correção ainda não está concluída: o documento sai marcado como provisório em todas as páginas.
                </p>
            </div>
            <div
                v-if="document.is_diagnostic"
                class="rounded-md border border-sky-300 bg-sky-50 px-4 py-3 text-sm text-sky-950 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100"
                role="status"
            >
                <p class="font-medium">Avaliação diagnóstica.</p>
                <p class="mt-1">Não conta para as médias classificativas.</p>
            </div>
        </div>

        <PrintableDocument
            :orientation="document.orientation"
            :compact="document.orientation === 'landscape'"
            :stamp="document.state.is_provisional ? document.state.label : null"
            page-numbers
            class="w-full rounded-lg border border-border bg-card p-6 sm:p-10"
        >
            <!-- The school's «not configured» note is advice to the teacher, not
                 part of the document. -->
            <div class="print:[&>p]:hidden">
                <ReportLetterhead :identity="document.identity" />
            </div>

            <div class="mt-8 border-b border-border pb-5">
                <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ document.kind_label }}</p>
                <h1 class="mt-1 font-serif text-[1.55rem] leading-tight">{{ document.title }}</h1>
                <p class="mt-1.5 text-sm text-muted-foreground">{{ document.subtitle }}</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ document.meta.type ?? 'Sem tipo' }} · {{ document.meta.purpose }} · {{ document.issued_label }}
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <!-- On paper too: a provisional grid must say so on every copy. This one is on
                         the first sheet; the stamp in the page margin (see PrintableDocument)
                         repeats it on every sheet where the browser draws margin boxes. -->
                    <Badge
                        :variant="document.state.is_provisional ? 'outline' : 'secondary'"
                        class="text-xs font-semibold"
                        :class="document.state.is_provisional ? 'border-amber-500 text-amber-800 print:border-black' : ''"
                    >
                        {{ document.state.label }}
                    </Badge>
                    <Badge v-if="document.is_diagnostic" variant="outline" class="text-xs">Avaliação diagnóstica</Badge>
                </div>
            </div>

            <p v-if="document.is_empty" class="mt-8 text-sm text-muted-foreground">{{ document.empty_message }}</p>

            <template v-else>
            <section
                v-for="(block, blockIndex) in document.blocks"
                :key="blockIndex"
                data-block
                class="mt-8 space-y-2"
                :class="blockIndex > 0 ? 'print:break-before-page' : ''"
            >
                <h2 v-if="block.caption" class="text-[0.95rem] leading-snug font-semibold">{{ block.caption }}</h2>

                <!-- On screen the table scrolls on a narrow viewport; on paper it
                     must not clip. -->
                <div class="overflow-x-auto print:overflow-visible">
                    <table class="w-full border-collapse text-xs tabular-nums">
                        <thead>
                            <tr v-if="block.group_spans" class="text-muted-foreground">
                                <th colspan="2" scope="colgroup"></th>
                                <th
                                    v-for="(span, spanIndex) in block.group_spans"
                                    :key="spanIndex"
                                    :colspan="span.span"
                                    scope="colgroup"
                                    class="border-b border-border px-1 py-1 text-center font-medium"
                                >
                                    {{ span.label }}
                                </th>
                            </tr>
                            <tr class="border-b border-border text-left">
                                <th scope="col" class="w-8 px-1 py-1.5 font-medium">Nº</th>
                                <th scope="col" class="min-w-32 px-1 py-1.5 font-medium">Nome</th>
                                <th
                                    v-for="(column, columnIndex) in block.columns"
                                    :key="columnIndex"
                                    scope="col"
                                    class="px-1 py-1.5 text-center font-medium"
                                >
                                    {{ headerOf(column) }}
                                </th>
                            </tr>
                            <tr v-if="hasQuotas(block)" class="border-b border-border text-muted-foreground">
                                <th colspan="2" scope="row" class="px-1 py-1 text-left font-normal">Cotação</th>
                                <td
                                    v-for="(column, columnIndex) in block.columns"
                                    :key="columnIndex"
                                    class="px-1 py-1 text-center"
                                >
                                    {{ column.quota ?? '' }}
                                </td>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, rowIndex) in block.rows" :key="rowIndex" class="border-b border-border align-top">
                                <td class="px-1 py-1.5">{{ row.number }}</td>
                                <th scope="row" class="px-1 py-1.5 text-left font-medium break-words">{{ row.name }}</th>
                                <td
                                    v-for="(cell, cellIndex) in row.cells"
                                    :key="cellIndex"
                                    class="px-1 py-1.5 text-center"
                                    :class="cell.negative ? 'font-semibold' : ''"
                                    :data-state="cell.state ?? undefined"
                                >
                                    {{ cell.text }}
                                    <span v-if="cell.detail" class="block text-[0.65rem] font-normal text-muted-foreground">
                                        {{ cell.detail }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            </template>

            <section v-if="document.charts" data-charts class="mt-8 space-y-6">
                <article v-for="(chart, chartIndex) in chartList" :key="chartIndex" data-chart class="space-y-2 break-inside-avoid">
                    <div>
                        <h2 class="text-[0.95rem] leading-snug font-semibold">{{ chart.title }}</h2>
                        <p class="text-xs text-muted-foreground">
                            {{ chart.basis }}<template v-if="'n_label' in chart"> · {{ chart.n_label }}</template>
                        </p>
                    </div>

                    <p v-if="chart.bars.length === 0" class="text-sm text-muted-foreground">{{ chart.empty_message }}</p>

                    <ul v-else class="space-y-1">
                        <li
                            v-for="(bar, barIndex) in chart.bars"
                            :key="barIndex"
                            class="grid grid-cols-[minmax(3.5rem,9rem)_1fr_minmax(5rem,auto)] items-center gap-x-3 text-xs"
                        >
                            <span class="font-medium break-words">{{ bar.label }}</span>
                            <!-- The track and the bar: the server measured the bar on an absolute
                                 axis. Backgrounds do not print unless told to, hence the
                                 print-color-adjust. A bar with no width — or none to draw — is an
                                 empty track, never a bar at zero (the PDF draws the same rule). -->
                            <span class="h-3.5 border border-border print:[print-color-adjust:exact]">
                                <span
                                    v-if="bar.width !== null && Number(bar.width) > 0"
                                    class="block h-full print:[print-color-adjust:exact]"
                                    :class="bar.negative ? 'border-r border-foreground bg-foreground/30' : 'bg-foreground/75'"
                                    :style="{ width: `${bar.width}%` }"
                                ></span>
                            </span>
                            <span class="tabular-nums">
                                {{ bar.value_text }}
                                <span v-if="bar.detail" class="block text-[0.65rem] text-muted-foreground">{{ bar.detail }}</span>
                            </span>
                        </li>
                    </ul>

                    <p v-if="'axis' in chart" class="text-[0.7rem] text-muted-foreground">{{ chart.axis }}</p>
                    <p v-if="'missing_note' in chart && chart.missing_note" class="text-[0.7rem] text-muted-foreground">
                        {{ chart.missing_note }}
                    </p>
                    <p v-if="'note' in chart" class="text-[0.7rem] text-muted-foreground">{{ chart.note }}</p>
                    <p v-if="chart.caution" class="text-[0.7rem] font-medium">{{ chart.caution }}</p>
                </article>
            </section>

            <section v-if="document.legend.questions" class="mt-8 space-y-2">
                <h2 class="text-[0.95rem] leading-snug font-semibold">Questões</h2>
                <div class="overflow-x-auto print:overflow-visible">
                    <table class="w-full border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-border text-left text-muted-foreground">
                                <th scope="col" class="px-1 py-1 font-medium">Código</th>
                                <th v-if="hasNamedGroups" scope="col" class="px-1 py-1 font-medium">Grupo</th>
                                <th scope="col" class="px-1 py-1 font-medium">Descrição</th>
                                <th scope="col" class="px-1 py-1 text-right font-medium">Cotação</th>
                                <th scope="col" class="px-1 py-1 font-medium">Domínios</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(question, questionIndex) in document.legend.questions"
                                :key="questionIndex"
                                class="border-b border-border align-top"
                            >
                                <th scope="row" class="px-1 py-1 text-left font-medium">{{ question.code }}</th>
                                <td v-if="hasNamedGroups" class="px-1 py-1">{{ question.group ?? '' }}</td>
                                <td class="px-1 py-1 break-words">{{ question.label }}</td>
                                <td class="px-1 py-1 text-right tabular-nums">
                                    {{ question.quota }}<span v-if="question.bonus"> · bónus</span>
                                </td>
                                <td class="px-1 py-1">{{ question.domains.join(' · ') || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="mt-8 space-y-1 border-t border-border pt-4 text-xs text-muted-foreground">
                <ul v-if="document.legend.states.length > 0" class="flex flex-wrap gap-x-4 gap-y-1">
                    <li v-for="state in document.legend.states" :key="state.short">
                        <span class="font-medium text-foreground">{{ state.short }}</span> {{ state.label }}
                    </li>
                </ul>
                <p v-for="(note, noteIndex) in document.notes" :key="noteIndex">{{ note }}</p>
                <p v-if="document.excluded_note">{{ document.excluded_note }}</p>
                <p>{{ document.notice }}</p>
            </div>
        </PrintableDocument>
    </div>
</template>
