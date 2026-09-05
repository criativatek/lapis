<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronDown, PenLine, Plus } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import TableShell from '@/components/TableShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { statusToneClasses } from '@/lib/statusTone';

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

/**
 * «28/05/2027», e não o «2027-05-28» do transporte. O par `T00:00:00Z` +
 * `timeZone: 'UTC'` é o idioma da casa para ler um Y-m-d sem o deixar
 * escorregar um dia no fuso (Grid.vue, Calendário).
 */
const appliedOnFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC',
});

function appliedOnLabel(assessment: Assessment): string {
    return appliedOnFormatter.format(new Date(`${assessment.applied_on}T00:00:00Z`));
}

/**
 * A barra diz de relance o que a fracção obriga a ler: quanto falta corrigir.
 * Verde quando fechou, âmbar enquanto há trabalho — os mesmos significados
 * das pílulas ao lado. Sem alunos aplicáveis não há barra nenhuma: um vazio
 * não é zero (§13.3).
 */
function progressPercent(assessment: Assessment): number | null {
    const progress = assessment.progress;

    if (progress === null || progress.applicable === 0) {
        return null;
    }

    return Math.round((100 * progress.completed) / progress.applicable);
}

function hasNoApplicableStudents(assessment: Assessment): boolean {
    return assessment.progress === null || assessment.progress.applicable === 0;
}
</script>

<template>
    <Head title="Grelhas de correção" />

    <div class="mx-auto w-full max-w-5xl space-y-6 p-4">
        <PageHeader title="Grelhas de correção" description="As grelhas já criadas, com o estado e o progresso da correção.">
            <template #actions>
                <Button v-if="canImportGrids" as-child variant="outline">
                    <Link href="/imports/correction/create">Importar resultados</Link>
                </Button>
                <!-- A acção principal é um BOTÃO, não um select disfarçado
                     (SUP-7BAAB7, segunda volta): a página tem uma cor forte e
                     é esta. A escolha da turma abre por baixo. -->
                <DropdownMenu v-if="classOptions.length">
                    <DropdownMenuTrigger as-child>
                        <Button>
                            <Plus class="size-4" /> Nova grelha
                            <ChevronDown class="size-4 opacity-70" aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="min-w-56">
                        <DropdownMenuItem
                            v-for="classOption in classOptions"
                            :key="classOption.ulid"
                            class="cursor-pointer"
                            @click="startNewAssessment(classOption.ulid)"
                        >
                            {{ classOption.label }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <p v-if="!canImportGrids" class="max-w-xs text-xs text-muted-foreground">
                    A importação de resultados de outras plataformas de aplicação de testes está disponível no
                    Lapispro&nbsp;Pro.
                </p>
            </template>
        </PageHeader>

        <!-- Filtros com rótulo, na grelha da casa (Alunos usa a mesma): um
             select solto não diz o que filtra até se abrir. -->
        <div class="flex flex-wrap items-end gap-3">
            <label class="grid gap-1 text-sm">
                <span class="text-xs font-medium text-muted-foreground">Estado</span>
                <div class="relative">
                    <select
                        :value="filters.status ?? ''"
                        class="h-9 w-full appearance-none rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        @change="applyFilter('status', ($event.target as HTMLSelectElement).value)"
                    >
                        <option value="">Todos</option>
                        <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <ChevronDown class="pointer-events-none absolute top-1/2 right-2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                </div>
            </label>
            <label class="grid gap-1 text-sm">
                <span class="text-xs font-medium text-muted-foreground">Finalidade</span>
                <div class="relative">
                    <select
                        :value="filters.purpose ?? ''"
                        class="h-9 w-full appearance-none rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        @change="applyFilter('purpose', ($event.target as HTMLSelectElement).value)"
                    >
                        <option value="">Todas</option>
                        <option v-for="option in purposeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <ChevronDown class="pointer-events-none absolute top-1/2 right-2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                </div>
            </label>
            <label class="grid gap-1 text-sm">
                <span class="text-xs font-medium text-muted-foreground">Período</span>
                <div class="relative">
                    <select
                        :value="filters.period ?? ''"
                        class="h-9 w-full appearance-none rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        @change="applyFilter('period', ($event.target as HTMLSelectElement).value)"
                    >
                        <option value="">Todos</option>
                        <option v-for="option in periodOptions" :key="option.id" :value="option.id">{{ option.label }}</option>
                    </select>
                    <ChevronDown class="pointer-events-none absolute top-1/2 right-2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                </div>
            </label>
        </div>

        <EmptyState
            v-if="assessments.length === 0"
            :title="hasActiveFilters() ? 'Nenhuma grelha corresponde aos filtros escolhidos.' : 'Ainda não tem grelhas de correção — crie um elemento de avaliação a partir de uma turma.'"
            :icon="PenLine"
        />

        <TableShell v-else>
            <template #head>
                <tr>
                        <th class="px-4 py-2.5 font-medium">Data</th>
                        <th class="px-4 py-2.5 font-medium">Grelha</th>
                        <th class="px-4 py-2.5 font-medium">Finalidade</th>
                        <th class="px-4 py-2.5 font-medium">Turma</th>
                        <th class="px-4 py-2.5 font-medium">Período</th>
                        <th class="px-4 py-2.5 font-medium">Estado</th>
                        <th class="px-4 py-2.5 font-medium">Progresso</th>
                        <th class="px-4 py-2.5 font-medium"></th>
                </tr>
            </template>
            <template #body>
                    <tr v-for="assessment in assessments" :key="assessment.ulid" class="hover:bg-muted/30">
                        <td class="px-4 py-3 whitespace-nowrap text-muted-foreground tabular-nums">{{ appliedOnLabel(assessment) }}</td>
                        <td class="px-4 py-3 font-medium">{{ assessment.title }}</td>
                        <td class="px-4 py-3"><Badge variant="outline" class="font-normal text-muted-foreground">{{ assessment.purpose_label }}</Badge></td>
                        <td class="px-4 py-3 whitespace-nowrap text-muted-foreground">{{ assessment.class_label }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ assessment.period }}</td>
                        <td class="px-4 py-3"><Badge variant="secondary" :class="statusToneClasses(assessment.status)">{{ assessment.state_label }}</Badge></td>
                        <td class="px-4 py-3">
                            <!-- A barra diz de relance o que a fracção obriga a ler. -->
                            <div class="flex items-center gap-2">
                                <div
                                    v-if="progressPercent(assessment) !== null"
                                    class="h-1.5 w-16 shrink-0 overflow-hidden rounded-full bg-muted"
                                    aria-hidden="true"
                                >
                                    <div
                                        class="h-full rounded-full"
                                        :class="assessment.progress?.complete ? 'bg-emerald-500' : 'bg-amber-500'"
                                        :style="{ width: `${progressPercent(assessment)}%` }"
                                    ></div>
                                </div>
                                <span class="whitespace-nowrap tabular-nums" :class="hasNoApplicableStudents(assessment) ? 'text-xs text-muted-foreground' : undefined">{{ progressLabel(assessment) }}</span>
                                <span v-if="assessment.progress && assessment.progress.under_review > 0" class="text-xs whitespace-nowrap text-amber-700 dark:text-amber-400">
                                    · {{ assessment.progress.under_review }} em revisão
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="assessment.status === 'draft' ? `/instruments/${assessment.ulid}/edit` : `/assessments/${assessment.ulid}`" class="text-sm font-medium text-primary hover:underline">{{ assessment.action_label }}</Link>
                        </td>
                    </tr>
            </template>
        </TableShell>
    </div>
</template>
