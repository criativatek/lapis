<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

type RowClassification =
    'new' | 'existing' | 'conflict' | 'invalid' | 'unsupported';

type PlanRow = {
    classification: RowClassification;
    reason: string | null;
    label?: string;
    name?: string;
    title?: string;
    pseudonym_code?: string;
};

type Counts = {
    new: number;
    existing: number;
    conflict: number;
    invalid: number;
    unsupported: number;
};

type Plan = {
    rows: Record<string, PlanRow[]>;
    counts: Record<string, Counts>;
    can_confirm: boolean;
};

/**
 * Every domain BuildImportPlan can classify, grouped the way a teacher
 * thinks about a backup rather than the way the database is normalised
 * (§54). Flat child rows without their own identity — profile_version_
 * domains/periods, item_domain_allocations, self_assessment_questions/
 * responses, instrument_groups — are deliberately left out of both lists:
 * their counts already fold into totalNew, and any issue on them still
 * surfaces in "Pontos a rever" (allIssueRows scans every domain, shown or
 * not), so nothing is silently hidden — only kept off the summary table.
 */
const domainGroups: {
    title: string;
    domains: { key: string; label: string }[];
}[] = [
    {
        title: 'Estrutura',
        domains: [
            { key: 'academic_years', label: 'Anos letivos' },
            { key: 'subjects', label: 'Disciplinas' },
            { key: 'classes', label: 'Turmas' },
            { key: 'students', label: 'Alunos' },
            { key: 'enrollments', label: 'Inscrições' },
            { key: 'academic_periods', label: 'Períodos letivos' },
            { key: 'scales', label: 'Escalas' },
            { key: 'instrument_types', label: 'Tipos de elementos' },
            { key: 'domains', label: 'Domínios' },
            { key: 'assessment_profiles', label: 'Perfis de avaliação' },
            { key: 'assessment_profile_versions', label: 'Versões de perfis' },
        ],
    },
    {
        title: 'Avaliação',
        domains: [
            { key: 'instruments', label: 'Elementos de avaliação' },
            { key: 'instrument_items', label: 'Itens' },
            { key: 'student_item_scores', label: 'Pontuações' },
            { key: 'classifications', label: 'Classificações' },
            {
                key: 'self_assessment_templates',
                label: 'Modelos de autoavaliação',
            },
            { key: 'self_assessments', label: 'Autoavaliações' },
        ],
    },
    {
        title: 'Acompanhamento',
        domains: [
            { key: 'interim_assessments', label: 'Avaliações intercalares' },
            { key: 'evidence_records', label: 'Registos pedagógicos' },
            { key: 'interventions', label: 'Estratégias e medidas' },
            { key: 'intervention_reviews', label: 'Revisões de estratégias' },
        ],
    },
    {
        title: 'Documentos',
        domains: [{ key: 'reports', label: 'Relatórios' }],
    },
];

const summaryGroups: {
    title: string;
    items: { key: string; label: string; tally?: boolean }[];
}[] = [
    {
        title: 'Estrutura',
        items: [
            { key: 'classes', label: 'Turmas', tally: true },
            { key: 'students', label: 'Alunos', tally: true },
            { key: 'enrollments', label: 'Inscrições', tally: true },
            { key: 'academic_periods_created', label: 'Períodos letivos' },
            { key: 'scales_created', label: 'Escalas' },
            { key: 'instrument_types_created', label: 'Tipos de elementos' },
            { key: 'domains_created', label: 'Domínios' },
            {
                key: 'assessment_profiles_created',
                label: 'Perfis de avaliação',
            },
            {
                key: 'assessment_profile_versions_created',
                label: 'Versões de perfis',
            },
        ],
    },
    {
        title: 'Avaliação',
        items: [
            { key: 'instruments_created', label: 'Elementos de avaliação' },
            { key: 'instrument_items_created', label: 'Itens' },
            { key: 'student_item_scores_created', label: 'Pontuações' },
            { key: 'classifications_created', label: 'Classificações' },
            { key: 'self_assessments_created', label: 'Autoavaliações' },
        ],
    },
    {
        title: 'Acompanhamento',
        items: [
            {
                key: 'interim_assessments_created',
                label: 'Avaliações intercalares',
            },
            { key: 'evidence_records_created', label: 'Registos pedagógicos' },
            { key: 'interventions_created', label: 'Estratégias e medidas' },
        ],
    },
    {
        title: 'Documentos',
        items: [{ key: 'reports_created', label: 'Relatórios' }],
    },
];

const props = defineProps<{
    dataImport: {
        ulid: string;
        status: string;
        status_label: string;
        original_filename: string | null;
        source_schema_version: number | null;
        source_app_version: string | null;
        source_generated_at: string | null;
        source_organization: {
            ulid: string;
            name: string;
            type: string;
        } | null;
        summary: Record<string, unknown> | null;
        failure_reason: string | null;
    };
    destination: { name: string; type: string };
    plan: Plan | null;
}>();

/**
 * Only groups that actually have something to show for this backup — a
 * purely structural restore should not render an empty "Documentos"
 * section full of zeroes.
 */
const visiblePlanGroups = computed(() => {
    if (props.plan === null) {
        return [];
    }

    const counts = props.plan.counts;

    return domainGroups
        .map((group) => ({
            ...group,
            domains: group.domains.filter((domain) => domain.key in counts),
        }))
        .filter(
            (group) =>
                group.domains.length > 0 &&
                group.domains.some((domain) => {
                    const tally = counts[domain.key];

                    return (
                        tally.new > 0 ||
                        tally.existing > 0 ||
                        tally.conflict > 0 ||
                        tally.invalid > 0 ||
                        tally.unsupported > 0
                    );
                }),
        );
});

function summaryTallyOf(key: string): { created: number; skipped: number } {
    const summary = props.dataImport.summary as Record<string, unknown> | null;
    const value = summary?.[key] as
        { created?: number; skipped?: number } | undefined;

    return { created: value?.created ?? 0, skipped: value?.skipped ?? 0 };
}

function summaryCountOf(key: string): number {
    const summary = props.dataImport.summary as Record<string, unknown> | null;
    const value = summary?.[key];

    return typeof value === 'number' ? value : 0;
}

const visibleSummaryGroups = computed(() => {
    if (props.dataImport.summary === null) {
        return [];
    }

    return summaryGroups
        .map((group) => ({
            ...group,
            items: group.items.filter((item) =>
                item.tally
                    ? summaryTallyOf(item.key).created > 0
                    : summaryCountOf(item.key) > 0,
            ),
        }))
        .filter((group) => group.items.length > 0);
});

const totalNew = computed(() => {
    if (props.plan === null) {
        return 0;
    }

    return Object.values(props.plan.counts).reduce((sum, c) => sum + c.new, 0);
});

const allIssueRows = computed(() => {
    if (props.plan === null) {
        return [];
    }

    return Object.values(props.plan.rows)
        .flat()
        .filter(
            (row) =>
                row.classification === 'conflict' ||
                row.classification === 'invalid' ||
                row.classification === 'unsupported',
        );
});

const confirmForm = useForm({});
function confirmImport(): void {
    confirmForm.post(`/data-imports/${props.dataImport.ulid}/confirm`);
}

const cancelForm = useForm({});
function cancelImport(): void {
    cancelForm.delete(`/data-imports/${props.dataImport.ulid}`);
}

function rowLabel(row: PlanRow): string {
    return row.label ?? row.name ?? row.title ?? row.pseudonym_code ?? '—';
}

const needingReassignment = computed(() => {
    const summary = props.dataImport.summary;

    if (summary === null || typeof summary !== 'object') {
        return 0;
    }

    const value = (summary as Record<string, unknown>)
        .classes_needing_reassignment;

    return typeof value === 'number' ? value : 0;
});
</script>

<template>
    <Head title="Importar dados" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <div>
            <Heading
                title="Importar dados"
                description="Reveja o que este backup contém antes de confirmar."
            />
            <Link
                href="/data-imports/create"
                class="text-sm text-muted-foreground hover:underline"
                >← Nova importação</Link
            >
        </div>

        <!-- Resultado: importação concluída -->
        <div
            v-if="dataImport.status === 'imported'"
            class="space-y-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950"
        >
            <h2
                class="text-sm font-medium text-emerald-800 dark:text-emerald-300"
            >
                Importação concluída
            </h2>
            <div v-if="dataImport.summary" class="space-y-4">
                <div v-for="group in visibleSummaryGroups" :key="group.title">
                    <h3
                        class="mb-1.5 text-xs font-semibold tracking-wider text-emerald-800/70 uppercase dark:text-emerald-300/70"
                    >
                        {{ group.title }}
                    </h3>
                    <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                        <div v-for="item in group.items" :key="item.key">
                            <p class="text-muted-foreground">
                                {{ item.label }}
                            </p>
                            <p class="font-medium">
                                <template v-if="item.tally">
                                    {{ summaryTallyOf(item.key).created }}
                                    criados ·
                                    {{ summaryTallyOf(item.key).skipped }}
                                    ignorados
                                </template>
                                <template v-else>
                                    {{ summaryCountOf(item.key) }} criados
                                </template>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <p v-if="needingReassignment > 0" class="text-sm">
                {{ needingReassignment }} turma(s) necessitam de reatribuição.
                <Link
                    href="/classes/reassignment"
                    class="font-medium text-primary hover:underline"
                    >Ver turmas a reatribuir</Link
                >
            </p>
        </div>

        <!-- Resultado: falhou -->
        <div
            v-else-if="dataImport.status === 'failed'"
            class="space-y-2 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950"
        >
            <h2 class="text-sm font-medium text-red-800 dark:text-red-300">
                A importação falhou
            </h2>
            <p class="text-sm text-red-700 dark:text-red-400">
                {{ dataImport.failure_reason }}
            </p>
        </div>

        <!-- Resultado: cancelada -->
        <div
            v-else-if="dataImport.status === 'cancelled'"
            class="rounded-lg border border-border p-4 text-sm text-muted-foreground"
        >
            Esta importação foi cancelada.
        </div>

        <!-- Preview, antes de confirmar -->
        <template v-else>
            <div
                class="grid gap-4 rounded-lg border border-border p-4 sm:grid-cols-2"
            >
                <div>
                    <h2
                        class="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Origem
                    </h2>
                    <dl class="space-y-1 text-sm">
                        <div>
                            <dt class="inline text-muted-foreground">
                                Organização:
                            </dt>
                            <dd class="inline">
                                {{
                                    dataImport.source_organization?.name ?? '—'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="inline text-muted-foreground">
                                Gerado em:
                            </dt>
                            <dd class="inline">
                                {{
                                    dataImport.source_generated_at
                                        ? new Date(
                                              dataImport.source_generated_at,
                                          ).toLocaleString('pt-PT')
                                        : '—'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="inline text-muted-foreground">
                                Versão do Lapispro:
                            </dt>
                            <dd class="inline">
                                {{ dataImport.source_app_version ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="inline text-muted-foreground">
                                Ficheiro:
                            </dt>
                            <dd class="inline">
                                {{ dataImport.original_filename ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                </div>
                <div>
                    <h2
                        class="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Destino
                    </h2>
                    <dl class="space-y-1 text-sm">
                        <div>
                            <dt class="inline text-muted-foreground">
                                Organização:
                            </dt>
                            <dd class="inline">{{ destination.name }}</dd>
                        </div>
                        <div>
                            <dt class="inline text-muted-foreground">Tipo:</dt>
                            <dd class="inline">
                                {{
                                    destination.type === 'institutional'
                                        ? 'Institucional'
                                        : 'Pessoal'
                                }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div
                v-if="plan"
                class="overflow-hidden rounded-lg border border-border"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Tipo</th>
                            <th class="px-4 py-2.5 text-right font-medium">
                                Novos
                            </th>
                            <th class="px-4 py-2.5 text-right font-medium">
                                Existentes
                            </th>
                            <th class="px-4 py-2.5 text-right font-medium">
                                Conflitos
                            </th>
                            <th class="px-4 py-2.5 text-right font-medium">
                                Inválidos
                            </th>
                            <th class="px-4 py-2.5 text-right font-medium">
                                Não suportados
                            </th>
                        </tr>
                    </thead>
                    <template
                        v-for="group in visiblePlanGroups"
                        :key="group.title"
                    >
                        <tbody class="border-t border-border">
                            <tr>
                                <td
                                    colspan="6"
                                    class="bg-muted/30 px-4 py-1.5 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    {{ group.title }}
                                </td>
                            </tr>
                        </tbody>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="domain in group.domains"
                                :key="domain.key"
                            >
                                <td class="px-4 py-3 font-medium">
                                    {{ domain.label }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ plan.counts[domain.key]?.new ?? 0 }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ plan.counts[domain.key]?.existing ?? 0 }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right tabular-nums"
                                    :class="
                                        (plan.counts[domain.key]?.conflict ??
                                            0) > 0
                                            ? 'text-amber-600'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ plan.counts[domain.key]?.conflict ?? 0 }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right tabular-nums"
                                    :class="
                                        (plan.counts[domain.key]?.invalid ??
                                            0) > 0
                                            ? 'text-red-600'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ plan.counts[domain.key]?.invalid ?? 0 }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{
                                        plan.counts[domain.key]?.unsupported ??
                                        0
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </template>
                </table>
            </div>

            <div
                v-if="allIssueRows.length > 0"
                class="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950"
            >
                <h2 class="font-medium text-amber-800 dark:text-amber-300">
                    Pontos a rever
                </h2>
                <ul
                    class="list-inside list-disc space-y-1 text-amber-900 dark:text-amber-200"
                >
                    <li
                        v-for="(row, index) in allIssueRows.slice(0, 20)"
                        :key="index"
                    >
                        <strong>{{ rowLabel(row) }}</strong> — {{ row.reason }}
                    </li>
                </ul>
                <p
                    v-if="allIssueRows.length > 20"
                    class="text-xs text-amber-700 dark:text-amber-400"
                >
                    E mais {{ allIssueRows.length - 20 }} ponto(s).
                </p>
            </div>

            <div class="flex items-center gap-3 border-t border-border pt-5">
                <Dialog>
                    <DialogTrigger as-child>
                        <Button :disabled="!plan?.can_confirm">
                            {{
                                plan?.can_confirm
                                    ? 'Importar dados'
                                    : 'Nada novo para importar'
                            }}
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader class="space-y-3">
                            <DialogTitle>Importar dados</DialogTitle>
                            <DialogDescription>
                                Serão criados {{ totalNew }} registo(s) na
                                organização
                                <strong>{{ destination.name }}</strong
                                >. Os dados existentes não serão substituídos.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button variant="secondary">Cancelar</Button>
                            </DialogClose>
                            <Button
                                :disabled="confirmForm.processing"
                                @click="confirmImport"
                            >
                                {{
                                    confirmForm.processing
                                        ? 'A importar…'
                                        : 'Confirmar importação'
                                }}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
                <Button
                    variant="outline"
                    :disabled="cancelForm.processing"
                    @click="cancelImport"
                    >Cancelar importação</Button
                >
            </div>
        </template>
    </div>
</template>
