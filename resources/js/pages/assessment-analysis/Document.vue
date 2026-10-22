<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CircleAlert, FileDown, Printer } from '@lucide/vue';
import { computed, nextTick } from 'vue';
import PrintableDocument from '@/components/documents/PrintableDocument.vue';
import Heading from '@/components/Heading.vue';
import ReportLetterhead from '@/components/reports/ReportLetterhead.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { AnalysisDocument } from '@/lib/analysisDocument';

/**
 * A Análise da Avaliação em papel (Pro) — a pré-visualização.
 *
 * O QUE SE IMPRIME É ESTA PRÉ-VISUALIZAÇÃO, e o PDF do servidor lê a MESMA estrutura. Esta página não
 * formata, não arredonda e não reescreve nada: cada célula, cada frase e cada contagem chega já escrita
 * (`BuildAnalysisDocument`), e é essa a razão por que o ecrã, o papel do browser e o PDF dizem o mesmo.
 *
 * AS OPÇÕES SÃO APRESENTAÇÃO. Escolhem que secções se desenham e se o documento nomeia alunos — por
 * omissão não nomeia; contagens, domínios e tendências estão sempre. Os momentos analisados são os da
 * página da análise, mantidos no endereço.
 */
const props = defineProps<{
    schoolClass: { ulid: string; label: string };
    document: AnalysisDocument;
    controls: {
        moments: Record<string, string>;
        options: { secoes: string; alunos: string };
        sections: Array<{ key: string; label: string }>;
        base_url: string;
        back: { href: string; label: string };
    };
}>();

const selected = computed(() => props.controls.options.secoes.split(',').filter((key) => key !== ''));
const includeStudents = computed(() => props.controls.options.alunos === '1');

function query(sections: string[], students: boolean): Record<string, string> {
    return { ...props.controls.moments, secoes: sections.join(','), alunos: students ? '1' : '0' };
}

const pdfHref = computed(() => `${props.controls.base_url}/pdf?${new URLSearchParams({ ...props.controls.moments, ...props.controls.options }).toString()}`);

function visit(sections: string[], students: boolean): void {
    router.get(props.controls.base_url, query(sections, students), { replace: true, preserveScroll: true, preserveState: true });
}

function toggleSection(key: string, checked: boolean | 'indeterminate'): void {
    if (typeof checked !== 'boolean') {
        return;
    }

    const next = props.controls.sections.map((section) => section.key).filter((sectionKey) => (sectionKey === key ? checked : selected.value.includes(sectionKey)));

    // The last one ticked stays: an empty document is not a request the screen ever makes.
    if (next.length === 0) {
        return;
    }

    visit(next, includeStudents.value);
}

function toggleStudents(checked: boolean | 'indeterminate'): void {
    if (typeof checked === 'boolean') {
        visit(selected.value, checked);
    }
}

function isLastSection(key: string): boolean {
    return selected.value.length === 1 && selected.value[0] === key;
}

// What prints is the preview itself (see PrintableDocument).
function print(): void {
    void nextTick(() => window.print());
}
</script>

<template>
    <Head title="Análise da Avaliação — documento" />

    <div class="mx-auto w-full max-w-5xl space-y-6 p-4">
        <div class="print:hidden">
            <Link :href="controls.back.href" class="inline-flex min-h-11 items-center text-sm text-muted-foreground hover:underline sm:min-h-0">
                ← {{ controls.back.label }}
            </Link>
        </div>

        <Heading class="print:hidden" title="Análise da Avaliação — documento" :description="`${schoolClass.label} — a análise em papel, com os valores e as contagens que a sustentam.`" />

        <div class="space-y-4 print:hidden" data-test="document-controls">
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                <label v-for="section in controls.sections" :key="section.key" class="flex min-h-11 items-center gap-2 text-sm">
                    <Checkbox
                        :id="`analysis-section-${section.key}`"
                        :model-value="selected.includes(section.key)"
                        :disabled="isLastSection(section.key)"
                        :data-test="`section-${section.key}`"
                        @update:model-value="(value) => toggleSection(section.key, value)"
                    />
                    {{ section.label }}
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                <label class="flex min-h-11 items-center gap-2 text-sm">
                    <Checkbox id="analysis-students" :model-value="includeStudents" data-test="include-students" @update:model-value="toggleStudents" />
                    Incluir a lista nominal de alunos
                </label>
                <div class="flex items-center gap-1">
                    <Button variant="outline" class="min-h-11" data-test="print" @click="print">
                        <Printer class="size-4" />
                        Imprimir
                    </Button>
                    <!-- A plain link, not an Inertia visit: a binary response cannot come back through one. -->
                    <Button variant="outline" class="min-h-11" as-child>
                        <a :href="pdfHref" data-test="pdf">
                            <FileDown class="size-4" />
                            PDF
                        </a>
                    </Button>
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                Gerado na hora, a partir dos dados atuais. Não fica guardado. Sem a lista nominal, o documento só traz contagens, domínios e tendências.
            </p>
        </div>

        <PrintableDocument class="mx-auto w-full max-w-[46rem] rounded-lg border border-border bg-card p-6 sm:p-10" data-test="document">
            <!-- The school's «not configured» note is advice to the teacher, not part of the document. -->
            <div class="print:[&>p]:hidden">
                <ReportLetterhead :identity="document.identity" />
            </div>

            <div class="mt-10 border-b border-border pb-5">
                <h1 class="font-serif text-[1.55rem] leading-tight">{{ document.title }}</h1>
                <p class="mt-1.5 text-sm text-muted-foreground">{{ document.subtitle }}</p>
                <p class="mt-1 text-xs text-muted-foreground">{{ document.issued_label }}</p>
                <dl class="mt-4 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[max-content_1fr]" data-test="identification">
                    <template v-for="row in document.identification" :key="row.label">
                        <dt class="text-muted-foreground">{{ row.label }}</dt>
                        <dd>{{ row.value }}</dd>
                    </template>
                </dl>
            </div>

            <div v-if="document.warnings.length" class="mt-6 rounded-md border border-amber-300/70 p-3 text-xs" data-test="warnings">
                <p class="flex items-center gap-1.5 font-semibold">
                    <CircleAlert class="size-3.5 print:hidden" aria-hidden="true" />
                    Avisos: cobertura, comparabilidade e dados em falta
                </p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                    <li v-for="warning in document.warnings" :key="warning">{{ warning }}</li>
                </ul>
            </div>

            <section v-for="section in document.sections" :key="section.key" class="mt-8 space-y-3" :data-test="`doc-section-${section.key}`">
                <h2 class="border-b border-border pb-1 text-[1.05rem] font-semibold">{{ section.title }}</h2>

                <template v-for="(block, index) in section.blocks" :key="index">
                    <h3 v-if="block.type === 'heading'" class="pt-2 text-sm font-semibold">{{ block.text }}</h3>

                    <p
                        v-else-if="block.type === 'paragraph'"
                        class="text-sm"
                        :class="block.tone === 'muted' ? 'text-xs text-muted-foreground' : block.tone === 'caution' ? 'font-medium' : ''"
                    >
                        {{ block.text }}
                    </p>

                    <dl v-else-if="block.type === 'keyvalues'" class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[minmax(0,34%)_1fr]">
                        <template v-for="item in block.items" :key="item.label">
                            <dt class="font-medium">{{ item.label }}</dt>
                            <dd>{{ item.value }}</dd>
                        </template>
                    </dl>

                    <div v-else-if="block.type === 'list'" class="text-sm">
                        <p v-if="block.title" class="font-medium">{{ block.title }}</p>
                        <ul class="list-disc space-y-1 pl-5">
                            <li v-for="item in block.items" :key="item">{{ item }}</li>
                        </ul>
                    </div>

                    <div v-else-if="block.type === 'table'" class="overflow-x-auto print:overflow-visible">
                        <table class="w-full border-collapse text-xs">
                            <thead>
                                <tr class="border-b border-border text-left text-muted-foreground">
                                    <th
                                        v-for="column in block.columns"
                                        :key="column.key"
                                        scope="col"
                                        class="py-1.5 pr-3 font-medium"
                                        :class="column.align === 'right' ? 'text-right' : ''"
                                    >
                                        {{ column.label }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, rowIndex) in block.rows" :key="rowIndex" class="border-b border-border/70 align-top">
                                    <td
                                        v-for="(cell, cellIndex) in row"
                                        :key="cellIndex"
                                        class="py-1.5 pr-3"
                                        :class="[block.columns[cellIndex].align === 'right' ? 'text-right tabular-nums' : '', cellIndex === 0 ? 'font-medium' : '']"
                                    >
                                        {{ cell }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </section>

            <p v-if="document.is_empty" class="mt-8 text-sm text-muted-foreground">Nenhuma secção selecionada.</p>

            <div class="mt-10 border-t border-border pt-4 text-xs text-muted-foreground">
                <p>{{ document.notice }}</p>
            </div>
        </PrintableDocument>
    </div>
</template>
