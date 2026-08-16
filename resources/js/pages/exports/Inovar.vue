<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, CircleX, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { coverageElementLine } from '@/lib/coverage';

/** One element the engine itself named as the reason a result is partial. */
type CoverageElement = { instrument: string; applied_on: string; reason: string; item_count: number };

type PartialCoverage = { student: string; domain: string; elements: CoverageElement[] };

type Summary = {
    matched_students: number;
    unmatched_students: number;
    mapped_domains: number;
    unmapped_domains: number;
    ready_cells: number;
    partial_coverage: PartialCoverage[];
    warnings: string[];
    blocking_errors: string[];
};

type Preview = {
    students: { row: number; process_number: string | null; display_name: string; matched: boolean; issues: string[] }[];
    domains: { inovar_column: string; inovar_header: string; lapis_domain: string | null; mapped: boolean; issues: string[] }[];
    summary: Summary;
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string };
    period: { ulid: string; label: string };
    token: string | null;
    preview: Preview | null;
}>();

const page = usePage();

/** Whatever the server flashed back — including a refused download. */
const pageErrors = computed(() => (page.props.errors ?? {}) as Record<string, string>);

const upload = useForm<{ template: File | null }>({ template: null });

const fileInput = ref<HTMLInputElement | null>(null);
const chosenName = ref<string | null>(null);
const preparing = ref(false);

const canGenerate = computed(() => props.preview !== null && props.preview.summary.blocking_errors.length === 0);

/** Only the results that will be written AND rest on partial evidence. */
const partial = computed<PartialCoverage[]>(() => props.preview?.summary.partial_coverage ?? []);

function base(): string {
    return `/classes/${props.schoolClass.ulid}/exports/inovar/${props.period.ulid}`;
}

/**
 * THE DOWNLOAD IS A PLAIN NAVIGATION, not an Inertia visit.
 *
 * Inertia's client requires an Inertia response and drops anything else, so a
 * file coming back through `useForm().post()` never reaches the browser and the
 * button appears to do nothing at all. This is an ordinary link to a GET route,
 * exactly like the instrument grid and the pauta export: the browser downloads
 * the attachment and stays where it is, and a refusal redirects back with the
 * reason on it.
 */
const downloadUrl = computed(() => (props.token === null ? '#' : `${base()}/${props.token}`));

function chooseFile(): void {
    fileInput.value?.click();
}

function onFileChosen(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    upload.template = file;
    chosenName.value = file?.name ?? null;
}

function submitTemplate(): void {
    if (upload.template === null) {
        return;
    }

    upload.post(base(), { forceFormData: true, preserveScroll: true });
}

/**
 * Purely a hint that the click landed. The download itself is the browser's,
 * and the page does not navigate, so this clears itself.
 */
function noteDownloadStarted(): void {
    preparing.value = true;
    window.setTimeout(() => (preparing.value = false), 2500);
}
</script>

<template>
    <Head :title="`Exportar para INOVAR — ${schoolClass.label}`" />

    <div class="mx-auto w-full max-w-3xl space-y-5 p-4">
        <div>
            <Heading
                title="Exportar para INOVAR"
                :description="`${schoolClass.label} · ${schoolClass.subject} · ${period.label}`"
            />
            <Link :href="`/classes/${schoolClass.ulid}/results/quadro-sintese`" class="text-sm text-muted-foreground hover:underline">
                ← Voltar ao Quadro Síntese
            </Link>
        </div>

        <!-- 1. o ficheiro -->
        <section class="space-y-3 rounded-lg border border-border p-4">
            <h2 class="text-sm font-semibold">1 · Carregar a grelha do INOVAR</h2>
            <p class="text-sm text-muted-foreground">
                Descarregue a grelha no INOVAR e carregue-a aqui. O LÁPIS preenche as menções e devolve
                <strong>o mesmo ficheiro</strong> — nada mais é alterado.
            </p>

            <!-- The native control's own button is unreliable to look at and
                 all but invisible in some browsers. It stays REAL and focusable
                 — the label drives it, so the keyboard and a screen reader get
                 the same control the pointer does. -->
            <div class="flex flex-wrap items-center gap-3">
                <input
                    id="inovar-template"
                    ref="fileInput"
                    type="file"
                    accept=".xls,.xlsx"
                    class="sr-only"
                    @change="onFileChosen"
                />
                <label
                    for="inovar-template"
                    tabindex="0"
                    class="inline-flex cursor-pointer items-center gap-2 rounded-md border border-border px-4 py-2 text-sm font-medium hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @keydown.enter.prevent="chooseFile"
                    @keydown.space.prevent="chooseFile"
                >
                    <Upload class="size-4" />
                    {{ chosenName === null ? 'Escolher grelha INOVAR' : 'Alterar grelha' }}
                </label>

                <span v-if="chosenName" class="text-sm text-muted-foreground">{{ chosenName }}</span>

                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                    :disabled="upload.template === null || upload.processing"
                    @click="submitTemplate"
                >
                    {{ upload.processing ? 'Validar grelha…' : 'Validar grelha' }}
                </button>
            </div>

            <p v-if="upload.errors.template" class="text-sm text-red-600">{{ upload.errors.template }}</p>
            <!-- A refusal on the way out of the download lands here, because the
                 server redirects back with it rather than failing in silence. -->
            <p v-if="pageErrors.template" class="text-sm text-red-600">{{ pageErrors.template }}</p>
        </section>

        <!-- 2. o que foi lido -->
        <section v-if="preview" class="space-y-3 rounded-lg border border-border p-4">
            <h2 class="text-sm font-semibold">2 · Pré-validação</h2>

            <ul class="space-y-1 text-sm">
                <li class="flex items-center gap-2">
                    <CircleCheck class="size-4 text-emerald-600" />
                    {{ preview.summary.matched_students }} alunos correspondidos pelo N.º de processo
                </li>
                <li class="flex items-center gap-2">
                    <CircleCheck class="size-4 text-emerald-600" />
                    {{ preview.summary.mapped_domains }} domínios associados
                </li>
                <li class="flex items-center gap-2">
                    <CircleCheck class="size-4 text-emerald-600" />
                    {{ preview.summary.ready_cells }} menções prontas a escrever
                </li>
            </ul>

            <ul v-if="preview.summary.warnings.length" class="space-y-1 text-sm text-amber-700 dark:text-amber-400">
                <li v-for="warning in preview.summary.warnings" :key="warning" class="flex items-start gap-2">
                    <CircleAlert class="mt-0.5 size-4 shrink-0" />{{ warning }}
                </li>
            </ul>

            <!--
              Which result, whose it is, and what is behind it. One case reads
              inline; several become a list the teacher opens, so the summary
              never turns into a wall of text.
            -->
            <div v-if="partial.length === 1" class="pl-6 text-sm text-amber-700 dark:text-amber-400">
                <p class="font-medium">{{ partial[0].student }} — {{ partial[0].domain }}</p>
                <p v-for="element in partial[0].elements" :key="`${element.instrument}-${element.reason}`">
                    {{ coverageElementLine(element) }}
                </p>
            </div>

            <details v-else-if="partial.length > 1" class="pl-6 text-sm text-amber-700 dark:text-amber-400">
                <summary class="cursor-pointer">Ver detalhes</summary>
                <div class="mt-2 space-y-2">
                    <div v-for="(row, index) in partial" :key="`${row.student}-${row.domain}-${index}`">
                        <p class="font-medium">{{ row.student }} — {{ row.domain }}</p>
                        <ul class="list-disc pl-5">
                            <li v-for="element in row.elements" :key="`${element.instrument}-${element.reason}`">
                                {{ coverageElementLine(element) }}
                            </li>
                        </ul>
                    </div>
                </div>
            </details>

            <!-- Nada é gerado enquanto isto existir. -->
            <ul v-if="preview.summary.blocking_errors.length" class="space-y-1 text-sm text-red-700 dark:text-red-400">
                <li v-for="error in preview.summary.blocking_errors" :key="error" class="flex items-start gap-2">
                    <CircleX class="mt-0.5 size-4 shrink-0" />{{ error }}
                </li>
            </ul>

            <details class="text-sm">
                <summary class="cursor-pointer text-muted-foreground">Ver as correspondências</summary>

                <div class="mt-3 space-y-3">
                    <div>
                        <p class="mb-1 text-xs font-medium text-muted-foreground">Domínios</p>
                        <ul class="space-y-0.5">
                            <li v-for="domain in preview.domains" :key="domain.inovar_column">
                                <span class="tabular-nums text-muted-foreground">{{ domain.inovar_column }}</span>
                                «{{ domain.inovar_header }}»
                                <template v-if="domain.mapped">→ {{ domain.lapis_domain }}</template>
                                <span v-else class="text-red-600">— sem correspondência</span>
                            </li>
                        </ul>
                    </div>

                    <div>
                        <p class="mb-1 text-xs font-medium text-muted-foreground">Alunos</p>
                        <ul class="space-y-0.5">
                            <li v-for="student in preview.students" :key="student.row">
                                <span class="tabular-nums text-muted-foreground">{{ student.process_number ?? '—' }}</span>
                                {{ student.display_name }}
                                <span v-if="!student.matched" class="text-red-600">— {{ student.issues.join(' ') }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </details>
        </section>

        <!-- 3. a confirmação -->
        <section v-if="preview" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-4">
            <p class="text-sm text-muted-foreground">
                <template v-if="canGenerate">A grelha volta com {{ preview.summary.ready_cells }} menções preenchidas.</template>
                <template v-else>Resolva os pontos assinalados a vermelho para poder gerar o ficheiro.</template>
            </p>
            <!-- An ordinary link, so the browser downloads the file itself and
                 the page stays where it is. -->
            <a
                v-if="canGenerate"
                :href="downloadUrl"
                class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90"
                @click="noteDownloadStarted"
            >
                {{ preparing ? 'A preparar o ficheiro…' : 'Gerar ficheiro INOVAR' }}
            </a>
            <button v-else type="button" class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground opacity-50" disabled>
                Gerar ficheiro INOVAR
            </button>
        </section>
    </div>
</template>
