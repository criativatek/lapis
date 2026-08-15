<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { PenLine } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';

type Progress = { applicable: number; completed: number; under_review: number; complete: boolean } | null;

type Assessment = {
    ulid: string;
    title: string;
    class_label: string;
    purpose: string;
    purpose_label: string;
    period: string;
    applied_on: string;
    status: string;
    state_label: string;
    action_label: string;
    items_count: number;
    progress: Progress;
};

type Option = { value: string; label: string };
type PeriodOption = { id: number; label: string };
type ClassOption = { ulid: string; label: string };

const props = defineProps<{
    assessments: Assessment[];
    filters: { status: string | null; purpose: string | null; period: number | null };
    statusOptions: Option[];
    purposeOptions: Option[];
    periodOptions: PeriodOption[];
    classOptions: ClassOption[];
}>();

// The entitlement decides whether the entry point exists at all. Hiding it is
// presentation only — the routes themselves are gated by `module:` on the
// server, so this is convenience rather than access control.
const canImportGrids = computed(() => usePage().props.modules.includes('correction_grid_import'));

const hasActiveFilters = () => props.filters.status !== null || props.filters.purpose !== null || props.filters.period !== null;

function applyFilter(key: 'status' | 'purpose' | 'period', value: string): void {
    const query: Record<string, string> = {};

    (['status', 'purpose', 'period'] as const).forEach((filterKey) => {
        const nextValue = filterKey === key ? value : props.filters[filterKey];

        if (nextValue !== null && nextValue !== '') {
            query[filterKey] = String(nextValue);
        }
    });

    router.get('/assessments', query, { preserveScroll: true, preserveState: true });
}

// Turma is a required part of instruments.create's own URL, not a form
// field — there is no ambient "current class" here (this list spans every
// class), so the teacher picks one to proceed. When a período filter is
// already active on this list, it rides along as ?period=, so
// InstrumentForm does not make the teacher re-pick information already
// known — InstrumentController::create() validates it still belongs to the
// chosen class before using it, and just ignores it otherwise.
function startNewAssessment(classUlid: string): void {
    if (!classUlid) {
        return;
    }

    const query = props.filters.period !== null ? `?period=${props.filters.period}` : '';

    router.get(`/classes/${classUlid}/instruments/create${query}`);
}

// applicable stays 0 in the underlying data either way — this only changes
// how a zero-applicable assessment reads on screen, so it is never mistaken
// for a normal "0/0" progress fraction.
function progressLabel(assessment: Assessment): string {
    const progress = assessment.progress;

    if (progress === null || progress.applicable === 0) {
        return 'Sem alunos aplicáveis';
    }

    return `${progress.completed}/${progress.applicable}`;
}

function hasNoApplicableStudents(assessment: Assessment): boolean {
    return assessment.progress === null || assessment.progress.applicable === 0;
}
</script>

<template>
    <Head title="Avaliações" />

    <div class="mx-auto w-full max-w-5xl space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading title="Avaliações" description="Os instrumentos já criados, com o estado e o progresso da correção." />
            <select
                v-if="classOptions.length"
                value=""
                class="h-9 rounded-md border border-primary bg-transparent px-3 text-sm text-primary"
                @change="startNewAssessment(($event.target as HTMLSelectElement).value)"
            >
                <option value="" disabled>+ Nova avaliação — escolher turma</option>
                <option v-for="classOption in classOptions" :key="classOption.ulid" :value="classOption.ulid">
                    {{ classOption.label }}
                </option>
            </select>
            <Link
                v-if="canImportGrids"
                href="/imports/correction/create"
                class="flex h-9 items-center rounded-md border border-border px-3 text-sm hover:bg-muted/40"
            >
                Importar grelha
            </Link>
            <p v-else class="max-w-xs text-xs text-muted-foreground">
                A importação de resultados de outras plataformas de aplicação de testes está disponível no
                LÁPIS&nbsp;Pro.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <select
                :value="filters.status ?? ''"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                @change="applyFilter('status', ($event.target as HTMLSelectElement).value)"
            >
                <option value="">Todos os estados</option>
                <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
            <select
                :value="filters.purpose ?? ''"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                @change="applyFilter('purpose', ($event.target as HTMLSelectElement).value)"
            >
                <option value="">Todas as finalidades</option>
                <option v-for="option in purposeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
            <select
                :value="filters.period ?? ''"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                @change="applyFilter('period', ($event.target as HTMLSelectElement).value)"
            >
                <option value="">Todos os períodos</option>
                <option v-for="option in periodOptions" :key="option.id" :value="option.id">{{ option.label }}</option>
            </select>
        </div>

        <div v-if="assessments.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <PenLine class="mx-auto mb-3 size-8 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">
                {{ hasActiveFilters() ? 'Nenhuma avaliação corresponde aos filtros escolhidos.' : 'Ainda não tem avaliações — crie um instrumento a partir de uma turma.' }}
            </p>
        </div>

        <div v-else class="overflow-hidden rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Data</th>
                        <th class="px-4 py-2.5 font-medium">Avaliação</th>
                        <th class="px-4 py-2.5 font-medium">Finalidade</th>
                        <th class="px-4 py-2.5 font-medium">Turma</th>
                        <th class="px-4 py-2.5 font-medium">Período</th>
                        <th class="px-4 py-2.5 font-medium">Estado</th>
                        <th class="px-4 py-2.5 font-medium">Progresso</th>
                        <th class="px-4 py-2.5 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="assessment in assessments" :key="assessment.ulid" class="hover:bg-muted/30">
                        <td class="px-4 py-3 text-muted-foreground">{{ assessment.applied_on }}</td>
                        <td class="px-4 py-3 font-medium">{{ assessment.title }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ assessment.purpose_label }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ assessment.class_label }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ assessment.period }}</td>
                        <td class="px-4 py-3"><Badge variant="secondary">{{ assessment.state_label }}</Badge></td>
                        <td class="px-4 py-3">
                            <span :class="hasNoApplicableStudents(assessment) ? 'text-xs text-muted-foreground' : undefined">{{ progressLabel(assessment) }}</span>
                            <span v-if="assessment.progress && assessment.progress.under_review > 0" class="ml-1.5 text-xs text-amber-700">
                                · {{ assessment.progress.under_review }} em revisão
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="`/assessments/${assessment.ulid}`" class="text-sm text-primary hover:underline">{{ assessment.action_label }}</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
