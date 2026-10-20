<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FileDown, Printer, Users } from '@lucide/vue';
import { computed, nextTick } from 'vue';
import PrintableDocument from '@/components/documents/PrintableDocument.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import ReportLetterhead from '@/components/reports/ReportLetterhead.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import NativeSelect from '@/components/ui/NativeSelect.vue';

type ClassOption = { ulid: string; label: string; subject: string; academic_year: string };

type Item = { label: string; framing: string | null; status: string | null; group: boolean };

type StudentRow = { number: number | null; name: string; implemented: Item[]; planned: Item[] };

type MeasuresMapDocument = {
    title: string;
    subtitle: string;
    identity: {
        name: string;
        header_lines: string[];
        footer_note: string | null;
        is_configured: boolean;
        logo_url: string | null;
    };
    issued_at: string;
    issued_label: string;
    columns: { implemented: boolean; planned: boolean };
    column_labels: { implemented: string; planned: string };
    summary: {
        students: number;
        students_with_measures: number;
        implemented: number | null;
        planned: number | null;
    };
    summary_line: string;
    class_wide: { implemented: Item[]; planned: Item[] };
    students: StudentRow[];
    excluded_note: string | null;
    criteria: string;
    empty_message: string;
    notice: string;
    is_empty: boolean;
};

type ColumnKey = 'implemented' | 'planned';

const props = defineProps<{
    classes: ClassOption[];
    schoolClass: ClassOption | null;
    filters: { implemented: boolean; planned: boolean };
    document: MeasuresMapDocument | null;
}>();

// The server decides what each column holds, what it is called and what each
// item is called; this page only knows there are two columns and in which
// order they are laid out. The headings come with the document, so the screen
// and the PDF cannot title them differently.
function columnTitle(key: ColumnKey): string {
    return props.document?.column_labels[key] ?? '';
}

const shownColumns = computed<ColumnKey[]>(() => {
    const columns = props.document?.columns ?? props.filters;

    return (['implemented', 'planned'] as const).filter((key) => columns[key]);
});

const classWideColumns = computed<ColumnKey[]>(() => {
    const document = props.document;

    return document ? shownColumns.value.filter((key) => document.class_wide[key].length > 0) : [];
});

function filterQuery(filters: { implemented: boolean; planned: boolean }) {
    return { implementadas: filters.implemented ? '1' : '0', a_implementar: filters.planned ? '1' : '0' };
}

const baseUrl = computed(() =>
    props.schoolClass ? `/reports/turma/${props.schoolClass.ulid}/mapa-de-medidas` : null,
);

const pdfHref = computed(() => {
    if (!baseUrl.value) {
        return null;
    }

    const query = filterQuery(props.filters);

    return `${baseUrl.value}/pdf?implementadas=${query.implementadas}&a_implementar=${query.a_implementar}`;
});

function classLabel(option: ClassOption): string {
    return `${option.label} · ${option.subject} · ${option.academic_year}`;
}

// Changing the class keeps the filters the teacher chose: the question being
// asked is the same, only the class differs.
function changeClass(ulid: string | number | null): void {
    if (typeof ulid !== 'string' || ulid === '' || ulid === props.schoolClass?.ulid) {
        return;
    }

    router.get(`/reports/turma/${ulid}/mapa-de-medidas`, filterQuery(props.filters));
}

function toggle(key: ColumnKey, checked: boolean | 'indeterminate'): void {
    if (!baseUrl.value || typeof checked !== 'boolean') {
        return;
    }

    router.get(baseUrl.value, filterQuery({ ...props.filters, [key]: checked }), {
        replace: true,
        preserveScroll: true,
        preserveState: true,
    });
}

// The last box still ticked is disabled, so both columns can never be off at
// once (the server would turn a forged URL like that back into both on).
function isLastChecked(key: ColumnKey): boolean {
    return props.filters[key] && !props.filters[key === 'implemented' ? 'planned' : 'implemented'];
}

// What prints is the preview itself (see PrintableDocument).
function print(): void {
    void nextTick(() => window.print());
}

// Framing, state and «em grupo» arrive as separate facts; the line is only
// their joining. A fact the server left null is simply not part of it.
function secondaryLine(item: Item): string {
    return [item.framing, item.status, item.group ? 'em grupo' : null]
        .filter((part): part is string => part !== null && part !== '')
        .join(' · ');
}

function studentHeading(student: StudentRow): string {
    return student.number === null ? student.name : `N.º ${student.number} · ${student.name}`;
}
</script>

<template>
    <Head title="Mapa de Medidas Educativas" />

    <div class="mx-auto w-full max-w-5xl space-y-6 p-4">
        <div class="print:hidden">
            <Link
                href="/reports"
                class="inline-flex min-h-11 items-center text-sm text-muted-foreground hover:underline sm:min-h-0"
            >
                ← Relatórios
            </Link>
        </div>

        <Heading
            class="print:hidden"
            title="Mapa de Medidas Educativas"
            description="Os alunos da turma com medidas registadas em Estratégias e Medidas — implementadas e a implementar."
        />

        <EmptyState
            v-if="classes.length === 0"
            class="print:hidden"
            title="Ainda não leciona nenhuma turma."
            :icon="Users"
        />

        <template v-else>
            <div class="space-y-4 print:hidden">
                <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
                    <label class="grid gap-1 text-sm">
                        <span class="text-xs font-medium text-muted-foreground">Turma</span>
                        <!-- min-h, não h: o casco do NativeSelect fixa h-9, e
                             um min-height maior ganha-lhe sem depender da ordem
                             das classes (≥44px de alvo táctil no telemóvel). -->
                        <NativeSelect
                            class="min-h-11 sm:min-h-9"
                            :model-value="schoolClass?.ulid ?? ''"
                            @update:model-value="changeClass"
                        >
                            <option v-if="!schoolClass" value="" disabled>Escolha uma turma</option>
                            <option v-for="option in classes" :key="option.ulid" :value="option.ulid">
                                {{ classLabel(option) }}
                            </option>
                        </NativeSelect>
                    </label>

                    <template v-if="schoolClass">
                        <label class="flex min-h-11 items-center gap-2 text-sm">
                            <Checkbox
                                id="measures-map-implemented"
                                :model-value="filters.implemented"
                                :disabled="isLastChecked('implemented')"
                                @update:model-value="(value) => toggle('implemented', value)"
                            />
                            {{ columnTitle('implemented') }}
                        </label>
                        <label class="flex min-h-11 items-center gap-2 text-sm">
                            <Checkbox
                                id="measures-map-planned"
                                :model-value="filters.planned"
                                :disabled="isLastChecked('planned')"
                                @update:model-value="(value) => toggle('planned', value)"
                            />
                            {{ columnTitle('planned') }}
                        </label>
                    </template>

                    <div v-if="document && pdfHref" class="flex items-center gap-1">
                        <Button variant="outline" class="min-h-11" @click="print">
                            <Printer class="size-4" />
                            Imprimir
                        </Button>
                        <!-- A plain link, not an Inertia visit: a binary response
                             cannot come back through one. -->
                        <Button variant="outline" class="min-h-11" as-child>
                            <a :href="pdfHref">
                                <FileDown class="size-4" />
                                PDF
                            </a>
                        </Button>
                    </div>
                </div>

                <div class="space-y-1 text-sm">
                    <Link
                        v-if="schoolClass"
                        :href="`/classes/${schoolClass.ulid}/interventions`"
                        class="inline-flex min-h-11 items-center text-primary hover:underline sm:min-h-0"
                    >
                        Atualizar medidas em Estratégias e Medidas
                    </Link>
                    <p class="text-xs text-muted-foreground">
                        Gerado na hora, a partir dos registos atuais. Não fica guardado como relatório.
                    </p>
                </div>
            </div>

            <p v-if="!schoolClass" class="text-sm text-muted-foreground print:hidden">
                Escolha uma turma para gerar o mapa.
            </p>

            <PrintableDocument v-else-if="document">
                <!-- The school's «not configured» note is advice to the teacher,
                     not part of the document. -->
                <div class="print:[&>p]:hidden">
                    <ReportLetterhead :identity="document.identity" />
                </div>

                <div class="mt-10 border-b border-border pb-5">
                    <h1 class="font-serif text-[1.55rem] leading-tight">{{ document.title }}</h1>
                    <p class="mt-1.5 text-sm text-muted-foreground">{{ document.subtitle }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">{{ document.issued_label }}</p>
                    <p class="mt-4 text-sm font-semibold tabular-nums">{{ document.summary_line }}</p>
                </div>

                <section v-if="classWideColumns.length > 0" class="mt-8 space-y-3">
                    <h2 class="text-[0.95rem] leading-snug font-semibold">Medidas dirigidas a toda a turma</h2>
                    <div class="grid gap-4 sm:grid-cols-2 print:grid-cols-2">
                        <div v-for="key in classWideColumns" :key="key" class="space-y-1.5">
                            <h3 class="text-xs font-medium text-muted-foreground">{{ columnTitle(key) }}</h3>
                            <ul class="space-y-1.5">
                                <li v-for="(item, index) in document.class_wide[key]" :key="index" class="text-sm">
                                    <span class="font-medium">{{ item.label }}</span>
                                    <span v-if="secondaryLine(item)" class="block text-xs text-muted-foreground">
                                        {{ secondaryLine(item) }}
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </section>

                <p v-if="document.is_empty" class="mt-8 text-sm text-muted-foreground">
                    {{ document.empty_message }}
                </p>

                <section v-else-if="document.students.length > 0" class="mt-8 space-y-3">
                    <h2 class="text-[0.95rem] leading-snug font-semibold">Alunos com medidas</h2>

                    <!-- On a phone, stacked blocks; from sm up and ALWAYS on paper,
                         the table. `print:` keeps the paper from ever getting the
                         stacked version. -->
                    <table class="hidden w-full table-fixed border-collapse text-sm sm:table print:table">
                        <thead>
                            <tr class="border-b border-border text-left text-xs text-muted-foreground">
                                <th scope="col" class="w-[30%] py-2 pr-3 font-medium">Aluno</th>
                                <th v-for="key in shownColumns" :key="key" scope="col" class="py-2 pr-3 font-medium">
                                    {{ columnTitle(key) }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(student, rowIndex) in document.students"
                                :key="rowIndex"
                                class="border-b border-border align-top"
                            >
                                <th scope="row" class="py-3 pr-3 text-left font-medium break-words">
                                    {{ studentHeading(student) }}
                                </th>
                                <td v-for="key in shownColumns" :key="key" class="py-3 pr-3">
                                    <ul v-if="student[key].length > 0" class="space-y-1.5">
                                        <li v-for="(item, index) in student[key]" :key="index">
                                            <span class="font-medium">{{ item.label }}</span>
                                            <span
                                                v-if="secondaryLine(item)"
                                                class="block text-xs text-muted-foreground"
                                            >
                                                {{ secondaryLine(item) }}
                                            </span>
                                        </li>
                                    </ul>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <ul class="divide-y divide-border border-y border-border sm:hidden print:hidden">
                        <li v-for="(student, rowIndex) in document.students" :key="rowIndex" class="space-y-2 py-3">
                            <p class="text-sm font-medium break-words">{{ studentHeading(student) }}</p>
                            <div v-for="key in shownColumns" :key="key" class="space-y-1">
                                <p class="text-xs font-medium text-muted-foreground">{{ columnTitle(key) }}</p>
                                <ul v-if="student[key].length > 0" class="space-y-1.5 text-sm">
                                    <li v-for="(item, index) in student[key]" :key="index">
                                        <span class="font-medium">{{ item.label }}</span>
                                        <span v-if="secondaryLine(item)" class="block text-xs text-muted-foreground">
                                            {{ secondaryLine(item) }}
                                        </span>
                                    </li>
                                </ul>
                                <p v-else class="text-sm text-muted-foreground">—</p>
                            </div>
                        </li>
                    </ul>
                </section>

                <div class="mt-10 space-y-1 border-t border-border pt-4 text-xs text-muted-foreground">
                    <p>{{ document.criteria }}</p>
                    <p v-if="document.excluded_note">{{ document.excluded_note }}</p>
                    <p>{{ document.notice }}</p>
                </div>
            </PrintableDocument>
        </template>
    </div>
</template>
