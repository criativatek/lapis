<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Printer } from '@lucide/vue';
import { computed, nextTick } from 'vue';
import PrintableDocument from '@/components/documents/PrintableDocument.vue';
import Heading from '@/components/Heading.vue';
import ReportLetterhead from '@/components/reports/ReportLetterhead.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import NativeSelect from '@/components/ui/NativeSelect.vue';

// A PRÉ-VISUALIZAÇÃO DA GRELHA DA TURMA. A página não calcula nada: recebe a
// estrutura que `EvaluationSheetGrid` compõe no servidor — cada texto, cada
// percentagem, cada marca — e só a desenha. As opções mudam o que é desenhado,
// nunca um valor.

type Orientation = 'auto' | 'portrait' | 'landscape';

type Cell = {
    text: string;
    detail: string | null;
    /** Quem diz o quê: a decisão do professor, a proposta do Lapispro, ou nada. */
    origin: 'decision' | 'proposal' | null;
    /** «prof.» — a decisão do professor dita por palavras, porque o papel não tem itálico. */
    mark: string | null;
};

type Column = { kind: string; label: string };

type Block = {
    caption: string | null;
    columns: Column[];
    group_spans: { label: string | null; span: number }[];
    rows: { number: string; name: string; cells: Cell[] }[];
};

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
    meta: {
        class: string;
        subject: string;
        academic_year: string;
        period_kind: string;
        period: string;
        moment: string;
        scope: string;
        reference_date: string | null;
    };
    source: { is_historical: boolean; label: string; kept_at: string | null; author: string | null };
    state: { is_provisional: boolean; label: string };
    options: { self_assessment: boolean; orientation: Orientation };
    self_assessment_available: boolean;
    orientation: 'portrait' | 'landscape';
    blocks: Block[];
    summary: { students: number; domains: number; blocks: number; decided: number; pending: number };
    notes: string[];
    warnings: string[];
    notice: string;
    is_empty: boolean;
    empty_message: string;
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string };
    source: {
        kind: 'live' | 'snapshot';
        moment: 'interim' | 'final' | null;
        back: { href: string; label: string };
        base_url: string;
        fixed_query: Record<string, string>;
    };
    document: GridDocument;
}>();

const orientationQuery: Record<Orientation, string> = { auto: 'auto', portrait: 'vertical', landscape: 'horizontal' };

/** The options as the query string the server reads (and normalises); the moment travels with them. */
function query(options: GridDocument['options']): Record<string, string> {
    return {
        ...props.source.fixed_query,
        autoavaliacao: options.self_assessment ? '1' : '0',
        orientacao: orientationQuery[options.orientation],
    };
}

function visit(options: GridDocument['options']): void {
    router.get(props.source.base_url, query(options), {
        replace: true,
        preserveScroll: true,
        preserveState: true,
    });
}

function setSelfAssessment(checked: boolean | 'indeterminate'): void {
    if (typeof checked !== 'boolean') {
        return;
    }

    visit({ ...props.document.options, self_assessment: checked });
}

function setOrientation(value: string | number | null): void {
    if (value !== 'auto' && value !== 'portrait' && value !== 'landscape') {
        return;
    }

    visit({ ...props.document.options, orientation: value });
}

const isHistorical = computed(() => props.document.source.is_historical);

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
                :href="source.back.href"
                class="inline-flex min-h-11 items-center text-sm text-muted-foreground hover:underline sm:min-h-0"
            >
                ← {{ source.back.label }}
            </Link>
        </div>

        <Heading
            class="print:hidden"
            :title="`${document.kind_label} — pré-visualização`"
            :description="`${document.title} · ${document.meta.class}`"
        />

        <!-- Controls: none of them changes a number, only what is drawn. -->
        <div class="space-y-4 print:hidden">
            <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
                <!-- Supporting information, not part of the Pauta: off unless asked for. -->
                <label class="flex min-h-11 items-center gap-2 text-sm" :class="{ 'opacity-60': !document.self_assessment_available }">
                    <Checkbox
                        id="sheet-doc-self-assessment"
                        :model-value="document.options.self_assessment"
                        :disabled="!document.self_assessment_available"
                        @update:model-value="setSelfAssessment"
                    />
                    Autoavaliação dos alunos
                    <span v-if="!document.self_assessment_available" class="text-xs text-muted-foreground">
                        (não há autoavaliação nesta pauta)
                    </span>
                </label>

                <label class="grid gap-1 text-sm">
                    <span class="text-xs font-medium text-muted-foreground">Orientação</span>
                    <NativeSelect
                        id="sheet-doc-orientation"
                        class="min-h-11 sm:min-h-9"
                        :model-value="document.options.orientation"
                        @update:model-value="setOrientation"
                    >
                        <option value="landscape">Horizontal</option>
                        <option value="portrait">Vertical</option>
                        <option value="auto">Automática</option>
                    </NativeSelect>
                </label>

                <Button variant="outline" class="min-h-11" @click="print">
                    <Printer class="size-4" />
                    Imprimir
                </Button>
            </div>

            <p class="text-xs text-muted-foreground">
                <template v-if="!isHistorical">
                    {{ document.source.label }}: gerado na hora, a partir dos registos atuais. Não fica guardado.
                </template>
                Orientação aplicada: {{ document.orientation === 'landscape' ? 'horizontal' : 'vertical' }}.
            </p>

            <div
                v-if="isHistorical"
                class="rounded-md border border-sky-300 bg-sky-50 px-4 py-3 text-sm text-sky-950 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100"
                role="status"
            >
                <p class="font-medium">Pauta guardada — vista só de leitura.</p>
                <p class="mt-1">
                    {{ document.source.label }}<template v-if="document.source.author"> por {{ document.source.author }}</template
                    >. Os valores são os do dia em que foi guardada e não refletem alterações posteriores.
                </p>
            </div>
            <div
                v-if="document.state.is_provisional"
                class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
                role="status"
            >
                <p class="font-medium">Valores provisórios.</p>
                <p class="mt-1">
                    {{ document.summary.pending === 1 ? '1 aluno ainda sem nível atribuído' : `${document.summary.pending} alunos ainda sem nível atribuído` }}
                    pelo professor: o documento sai marcado como provisório em todas as páginas.
                </p>
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
                    {{ document.meta.academic_year }} · {{ document.meta.period_kind }} — {{ document.meta.period }} ·
                    {{ document.meta.scope }}<template v-if="document.meta.reference_date">
                        · Data de referência {{ document.meta.reference_date }}</template
                    >
                    · {{ document.issued_label }}
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
                    <Badge variant="outline" class="text-xs">{{ document.source.label }}</Badge>
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
                                <tr class="text-muted-foreground">
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
                                        {{ column.label }}
                                    </th>
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
                                        :data-origin="cell.origin ?? undefined"
                                    >
                                        {{ cell.text }}<span v-if="cell.mark" class="ml-1 text-[0.65rem] font-semibold">{{ cell.mark }}</span>
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

            <div class="mt-8 space-y-1 border-t border-border pt-4 text-xs text-muted-foreground">
                <p v-for="(note, noteIndex) in document.notes" :key="`n${noteIndex}`">{{ note }}</p>
                <p v-for="(warning, warningIndex) in document.warnings" :key="`w${warningIndex}`">
                    <span class="font-medium text-foreground">Aviso registado:</span> {{ warning }}
                </p>
                <p>{{ document.notice }}</p>
            </div>
        </PrintableDocument>
    </div>
</template>
