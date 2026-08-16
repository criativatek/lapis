<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, CircleX, Upload } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';

type Summary = {
    matched_students: number;
    unmatched_students: number;
    mapped_domains: number;
    unmapped_domains: number;
    ready_cells: number;
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

const upload = useForm<{ template: File | null }>({ template: null });
// Carries no fields of its own — everything is decided server-side from the
// file already on disk — but it does carry back the refusal when the server
// says no.
const generate = useForm<{ template: null }>({ template: null });

const canGenerate = computed(() => props.preview !== null && props.preview.summary.blocking_errors.length === 0);

function base(): string {
    return `/classes/${props.schoolClass.ulid}/exports/inovar/${props.period.ulid}`;
}

function submitTemplate(): void {
    upload.post(base(), { forceFormData: true, preserveScroll: true });
}

function submitGeneration(): void {
    if (! canGenerate.value || props.token === null) {
        return;
    }

    generate.post(`${base()}/${props.token}`, { preserveScroll: true });
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

            <div class="flex flex-wrap items-center gap-3">
                <input
                    type="file"
                    accept=".xls,.xlsx"
                    class="text-sm"
                    @change="upload.template = ($event.target as HTMLInputElement).files?.[0] ?? null"
                />
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                    :disabled="upload.template === null || upload.processing"
                    @click="submitTemplate"
                >
                    <Upload class="size-4" />
                    Validar grelha
                </button>
            </div>

            <p v-if="upload.errors.template" class="text-sm text-red-600">{{ upload.errors.template }}</p>
            <p v-if="generate.errors.template" class="text-sm text-red-600">{{ generate.errors.template }}</p>
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
            <button
                type="button"
                class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                :disabled="!canGenerate || generate.processing"
                @click="submitGeneration"
            >
                Gerar ficheiro INOVAR
            </button>
        </section>
    </div>
</template>
