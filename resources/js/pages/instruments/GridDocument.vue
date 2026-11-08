<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FileDown, Printer } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import PrintableDocument from '@/components/documents/PrintableDocument.vue';
import Heading from '@/components/Heading.vue';
import ReportLetterhead from '@/components/reports/ReportLetterhead.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { SAFETY_PX, fitNotice, fitToPage, usableAreaPx } from '@/lib/printFit';

type Orientation = 'auto' | 'portrait' | 'landscape';

/** «Uma página»: um só bloco encolhido até caber; «blocos»: as partes de sempre. */
type Layout = 'one_page' | 'blocks';

type Cell = { text: string; short: string | null; detail: string | null; state: string | null; negative: boolean | null };

type Column = {
    kind: 'item' | 'raw' | 'result' | 'level' | 'domain';
    /** Only a question has a code and a group. */
    code?: string;
    group?: string | null;
    label?: string;
    /** The compact header: the question's number, or the summary column's short form. */
    short: string;
    quota: string | null;
    bonus?: boolean;
};

type Block = {
    caption: string | null;
    columns: Column[];
    group_spans: { label: string | null; short: string | null; span: number }[] | null;
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
    options: { raw_score: boolean; result: boolean; domains: boolean; legend: boolean; charts: boolean; orientation: Orientation; layout: Layout };
    charts_available: boolean;
    charts: {
        distribution: Chart & { n_label: string; missing: { label: string; count: number }[]; missing_note: string | null; unplaced_note: string | null };
        means: Chart & { axis: string };
        questions: Chart & { axis: string; note: string };
    } | null;
    orientation: 'portrait' | 'landscape';
    layout: Layout;
    blocks: Block[];
    /** Every short form of the sheet, spelled out once, for the one-line legend. */
    abbreviations: { short: string; long: string }[];
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

const layoutQuery: Record<Layout, string> = { one_page: 'uma-pagina', blocks: 'blocos' };

/** A escolha do professor, lembrada neste browser por tipo de documento. */
const LAYOUT_STORAGE_KEY = 'lapis:print-layout:instrument-grid';

/** The options as the query string the server reads (and normalises). */
function query(options: GridDocument['options']): Record<string, string> {
    return {
        bruta: options.raw_score ? '1' : '0',
        resultado: options.result ? '1' : '0',
        dominios: options.domains ? '1' : '0',
        legenda: options.legend ? '1' : '0',
        graficos: options.charts ? '1' : '0',
        orientacao: orientationQuery[options.orientation],
        disposicao: layoutQuery[options.layout],
    };
}

const isOnePage = computed(() => props.document.layout === 'one_page');

function rememberedLayout(): Layout | null {
    try {
        const value = localStorage.getItem(LAYOUT_STORAGE_KEY);

        return value === 'one_page' || value === 'blocks' ? value : null;
    } catch {
        return null;
    }
}

function rememberLayout(layout: Layout): void {
    try {
        localStorage.setItem(LAYOUT_STORAGE_KEY, layout);
    } catch {
        // O armazenamento do browser é opcional.
    }
}

function setLayout(value: string | number | null): void {
    if (value !== 'one_page' && value !== 'blocks') {
        return;
    }

    rememberLayout(value);
    router.get(baseUrl.value, query({ ...props.document.options, layout: value }), {
        replace: true,
        preserveScroll: true,
        preserveState: true,
    });
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
    if (isOnePage.value) {
        return column.short;
    }

    return column.kind === 'item' ? (column.code ?? '') : (column.label ?? '');
}

/** O texto de uma célula no papel compacto: a sigla do nível quando a célula é só o nível. */
function cellText(cell: Cell): string {
    return isOnePage.value && cell.short !== null && cell.detail === null ? cell.short : cell.text;
}

/** O detalhe (a menção do domínio): em sigla no papel compacto. */
function cellDetail(cell: Cell): string | null {
    if (!isOnePage.value || cell.detail === null) {
        return cell.detail;
    }

    return cell.short ?? cell.detail;
}

function hasQuotas(block: Block): boolean {
    return block.columns.some((column) => column.quota !== null);
}

/**
 * ============================== AJUSTE A UMA PÁGINA ==============================
 *
 * A página mede a tabela (com a sua legenda) e o cabeçalho do documento e deixa a
 * decisão a `printFit`; a escala vai na prop `fit` do `PrintableDocument`, que a
 * publica numa variável CSS lida pelo `zoom` de `.document-print-fit`. No jsdom
 * tudo mede zero e a escala fica em 1 — a página não faz aritmética.
 */
const printDocumentRef = ref<InstanceType<typeof PrintableDocument> | null>(null);
const fitRef = ref<HTMLElement | null>(null);
const fit = ref<number>(1);
const notice = ref<string | null>(null);

function measure(): void {
    if (!isOnePage.value) {
        fit.value = 1;
        notice.value = null;

        return;
    }

    const table = fitRef.value;
    const root = printDocumentRef.value?.$el as HTMLElement | null | undefined;

    if (table === null || !root) {
        return;
    }

    const usable = usableAreaPx('A4', 'landscape');

    // Mede-se À LARGURA DO PAPEL, não à do ecrã: um parágrafo de notas quebra
    // em mais linhas numa folha de 277 mm do que num contentor mais largo, e a
    // diferença era uma linha de rodapé a escorregar para a folha seguinte. O
    // cartão do ecrã tem um preenchimento que o papel não tem: desconta-se.
    const rootStyle = getComputedStyle(root);
    const padX = parseFloat(rootStyle.paddingLeft) + parseFloat(rootStyle.paddingRight);
    const padTop = parseFloat(rootStyle.paddingTop);
    const padBottom = parseFloat(rootStyle.paddingBottom);
    const previousRootWidth = root.style.width;
    root.style.width = `${usable.width + (Number.isFinite(padX) ? padX : 0)}px`;

    // A largura NATURAL das tabelas: têm `w-full`, e medi-las assim daria a
    // largura do contentor, não a do conteúdo. Solta-se a largura de cada
    // TABELA (não do invólucro: a legenda é um parágrafo, e com a largura solta
    // deixaria de quebrar linha e mediria uma linha quilométrica).
    const tables = Array.from(table.querySelectorAll<HTMLTableElement>('table'));
    const previousWidths = tables.map((candidate) => candidate.style.width);
    tables.forEach((candidate) => {
        candidate.style.width = 'max-content';
    });
    const tableWidth = Math.max(0, ...tables.map((candidate) => candidate.offsetWidth));
    tables.forEach((candidate, index) => {
        candidate.style.width = previousWidths[index];
    });

    // Tudo o que fica acima e abaixo do que se encolhe — margens incluídas, que
    // o `offsetHeight` de cada bloco não conta e a folha conta.
    const rootRect = root.getBoundingClientRect();
    const fitRect = table.getBoundingClientRect();
    const headerHeight = fitRect.top - rootRect.top - (Number.isFinite(padTop) ? padTop : 0);
    const footerHeight = rootRect.bottom - fitRect.bottom - (Number.isFinite(padBottom) ? padBottom : 0);
    const tableHeight = fitRect.height;

    root.style.width = previousRootWidth;

    const result = fitToPage({
        tableWidth,
        tableHeight,
        headerHeight,
        footerHeight,
        slack: SAFETY_PX,
        usable,
    });

    fit.value = result.scale;
    notice.value = fitNotice(result);
}

onMounted(() => {
    const remembered = rememberedLayout();

    // A disposição lembrada neste browser vence a que o servidor serviu por
    // omissão — uma vez, e com `replace` para não encher o histórico.
    if (remembered !== null && remembered !== props.document.options.layout) {
        router.get(baseUrl.value, query({ ...props.document.options, layout: remembered }), {
            replace: true,
            preserveScroll: true,
            preserveState: true,
        });

        return;
    }

    void nextTick(measure);
});

watch(
    () => props.document,
    () => void nextTick(measure),
);

// What prints is the preview itself (see PrintableDocument).
function print(): void {
    measure();
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
                    <span class="text-xs font-medium text-muted-foreground">Disposição</span>
                    <NativeSelect
                        id="grid-doc-layout"
                        class="min-h-11 sm:min-h-9"
                        :model-value="document.options.layout"
                        @update:model-value="setLayout"
                    >
                        <option value="one_page">Uma página</option>
                        <option value="blocks">Blocos (várias páginas)</option>
                    </NativeSelect>
                </label>

                <!-- «Uma página» é sempre horizontal: a orientação só conta nos blocos. -->
                <label class="grid gap-1 text-sm" :class="{ 'opacity-60': isOnePage }">
                    <span class="text-xs font-medium text-muted-foreground">Orientação</span>
                    <NativeSelect
                        id="grid-doc-orientation"
                        class="min-h-11 sm:min-h-9"
                        :model-value="document.options.orientation"
                        :disabled="isOnePage"
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

            <!-- A escala decidida para o papel, dita no ecrã e nunca na folha. -->
            <p v-if="notice" data-fit-notice class="text-xs text-muted-foreground" role="status">{{ notice }}</p>

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
            ref="printDocumentRef"
            :orientation="document.orientation"
            :compact="isOnePage || document.orientation === 'landscape'"
            :stamp="document.state.is_provisional ? document.state.label : null"
            :fit="fit"
            page-numbers
            class="w-full rounded-lg border border-border bg-card p-6 sm:p-10"
        >
            <!-- The school's «not configured» note is advice to the teacher, not
                 part of the document. -->
            <div class="print:[&>p]:hidden">
                <ReportLetterhead :identity="document.identity" />
            </div>

            <!-- Em «uma página» o cabeçalho encolhe também: cada milímetro acima da tabela é um aluno a menos na folha. -->
            <div class="border-b border-border" :class="isOnePage ? 'mt-3 pb-2' : 'mt-8 pb-5'">
                <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ document.kind_label }}</p>
                <h1 class="font-serif leading-tight" :class="isOnePage ? 'text-lg' : 'mt-1 text-[1.55rem]'">{{ document.title }}</h1>
                <p class="text-muted-foreground" :class="isOnePage ? 'text-xs' : 'mt-1.5 text-sm'">{{ document.subtitle }}</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ document.meta.type ?? 'Sem tipo' }} · {{ document.meta.purpose }} · {{ document.issued_label }}
                </p>
                <div class="flex flex-wrap items-center gap-2" :class="isOnePage ? 'mt-1.5' : 'mt-3'">
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

            <!-- O que a escala de «uma página» encolhe: as tabelas e a legenda das siglas, nunca o cabeçalho. -->
            <div v-else ref="fitRef" class="document-print-fit">
            <section
                v-for="(block, blockIndex) in document.blocks"
                :key="blockIndex"
                data-block
                class="space-y-2"
                :class="[isOnePage ? 'mt-3' : 'mt-8', blockIndex > 0 ? 'print:break-before-page' : '']"
            >
                <h2 v-if="block.caption" class="text-[0.95rem] leading-snug font-semibold">{{ block.caption }}</h2>

                <!-- On screen the table scrolls on a narrow viewport; on paper it
                     must not clip. -->
                <div class="overflow-x-auto print:overflow-visible">
                    <table class="w-full border-collapse tabular-nums" :class="isOnePage ? 'grid-one-page' : 'text-xs'">
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
                                    {{ isOnePage ? span.short : span.label }}
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
                                <th
                                    scope="row"
                                    class="px-1 py-1.5 text-left font-medium"
                                    :class="isOnePage ? 'max-w-[24ch] truncate' : 'break-words'"
                                    :title="isOnePage ? row.name : undefined"
                                >
                                    {{ row.name }}
                                </th>
                                <td
                                    v-for="(cell, cellIndex) in row.cells"
                                    :key="cellIndex"
                                    class="px-1 py-1.5 text-center"
                                    :class="cell.negative ? 'font-semibold' : ''"
                                    :data-state="cell.state ?? undefined"
                                >
                                    {{ cellText(cell) }}
                                    <span
                                        v-if="cellDetail(cell)"
                                        :class="isOnePage ? 'ml-0.5 font-normal' : 'block text-[0.65rem] font-normal text-muted-foreground'"
                                    >
                                        {{ cellDetail(cell) }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Cada sigla por extenso, numa linha; faz parte do que se encolhe. -->
            <p v-if="isOnePage && document.abbreviations.length > 0" data-abbreviations class="mt-2 text-[7pt] leading-snug text-muted-foreground">
                <template v-for="(entry, index) in document.abbreviations" :key="entry.short">
                    <span class="font-medium text-foreground">{{ entry.short }}</span> = {{ entry.long
                    }}<template v-if="index < document.abbreviations.length - 1"> · </template>
                </template>
            </p>
            </div>

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
                    <p v-if="'unplaced_note' in chart && chart.unplaced_note" class="text-[0.7rem] text-muted-foreground">
                        {{ chart.unplaced_note }}
                    </p>
                    <p v-if="'note' in chart" class="text-[0.7rem] text-muted-foreground">{{ chart.note }}</p>
                    <p v-if="chart.caution" class="text-[0.7rem] font-medium">{{ chart.caution }}</p>
                </article>
            </section>

            <!-- Em «uma página» a legenda das questões, quando pedida, sai numa folha própria. -->
            <section v-if="document.legend.questions" class="mt-8 space-y-2" :class="isOnePage ? 'print:break-before-page' : ''">
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

            <!-- As notas ficam abaixo da tabela, sem escala: a medição desconta-as da altura útil. -->
            <div
                class="space-y-1 border-t border-border text-muted-foreground"
                :class="isOnePage ? 'mt-2 pt-1 text-[6.5pt] leading-snug' : 'mt-8 pt-4 text-xs'"
            >
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

<style scoped>
/* A tabela do modo «Uma página»: letra de 7,5 pt antes da escala (`printFit`),
   colunas ao mínimo que os números pedem, linhas baixas. Vale no ecrã e no
   papel, para a pré-visualização ser o que se imprime. Sem `@page` e sem
   `body *`: isso é do PrintableDocument. */
.grid-one-page {
    font-size: 7.5pt;
    line-height: 1.1;
}
.grid-one-page th,
.grid-one-page td {
    padding: 0.5pt 2pt;
}
.grid-one-page th[scope='col'],
.grid-one-page td {
    min-width: 3ch;
    white-space: nowrap;
}
.grid-one-page th[scope='row'] {
    white-space: nowrap;
}
</style>
