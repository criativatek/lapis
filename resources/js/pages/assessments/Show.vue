<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import StudentAvatar from '@/components/StudentAvatar.vue';
import TableShell from '@/components/TableShell.vue';
import { Badge } from '@/components/ui/badge';
import { percentFor, qualitativeLabelFor } from '@/lib/instrumentQualitativeRating';

type InstrumentHeader = {
    ulid: string;
    title: string;
    class_label: string;
    period: string;
    applied_on: string;
    purpose_label: string;
    counts_toward_classification: boolean;
    state_label: string;
};

type Summary = { applicable: number; completed: number; pending: number; absent: number; under_review: number };

type StudentRow = {
    enrollment_id: number;
    name: string;
    photo_url: string | null;
    class_number: number | null;
    state_key: string;
    state_label: string;
    state_detail: string | null;
    action_label: string;
};

type NonApplicableRow = { enrollment_id: number; name: string; photo_url: string | null; class_number: number | null };

type Item = { id: number; points_possible: number; is_bonus: boolean };
type Score = { enrollment_id: number; instrument_item_id: number; result_state: string; points_earned: number | null };
type ScaleBand = { label: string; band_min: string; band_max: string };

const props = defineProps<{
    instrument: InstrumentHeader;
    summary: Summary;
    students: StudentRow[];
    nonApplicableStudents: NonApplicableRow[];
    items: Item[];
    scores: Score[];
    scaleBands: ScaleBand[];
}>();

const scoreByCell = computed(() => {
    const map = new Map<string, Score>();

    for (const score of props.scores) {
        map.set(`${score.enrollment_id}:${score.instrument_item_id}`, score);
    }

    return map;
});

// Reuses, unchanged, the same math instruments/Grid.vue computes its own
// Total/Apreciação Qualitativa columns with — one implementation, fed here by
// the same items/scores shape the grid itself receives.
//
// Only shown for a student whose derived state is "assessed" (Corrigida) —
// every other state (por corrigir, em revisão, faltou, dispensada, anulada,
// não aplicável, situação especial) reads "—" here, even when some items
// already carry a percentage-worthy mark, so a partial or non-standard
// correction never looks like a finished result.
function resultLabelFor(student: StudentRow): string {
    if (student.state_key !== 'assessed') {
        return '—';
    }

    const cellFor = (itemId: number) => {
        const score = scoreByCell.value.get(`${student.enrollment_id}:${itemId}`);

        return score ? { state: score.result_state, points: score.points_earned } : undefined;
    };

    const percent = percentFor(props.items, cellFor);

    if (percent === null) {
        return '—';
    }

    const qualitative = qualitativeLabelFor(percent, props.scaleBands);

    return qualitative ? `${percent}% · ${qualitative}` : `${percent}%`;
}

const stateBadgeClass: Record<string, string> = {
    assessed: 'bg-emerald-100 text-emerald-800',
    pending: 'bg-muted text-muted-foreground',
    under_review: 'bg-amber-100 text-amber-900',
    absent: 'bg-rose-100 text-rose-800',
    special: 'bg-violet-100 text-violet-900',
};
</script>

<template>
    <Head :title="`Avaliação — ${instrument.title}`" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <div>
            <p class="text-sm text-muted-foreground">Avaliação</p>
            <Heading :title="instrument.title" />
            <Link href="/assessments" class="text-sm text-muted-foreground hover:underline">← Todas as grelhas</Link>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
            <span>{{ instrument.class_label }}</span>
            <span>·</span>
            <span>{{ instrument.period }}</span>
            <span>·</span>
            <span>{{ instrument.applied_on }}</span>
            <span>·</span>
            <span>{{ instrument.purpose_label }}</span>
            <span>·</span>
            <span>Contabiliza para classificação: {{ instrument.counts_toward_classification ? 'Sim' : 'Não' }}</span>
            <Badge variant="secondary">{{ instrument.state_label }}</Badge>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div class="rounded-lg border border-border p-3">
                <p class="text-xs text-muted-foreground">Alunos aplicáveis</p>
                <p class="text-xl font-semibold tabular-nums">{{ summary.applicable }}</p>
            </div>
            <div class="rounded-lg border border-border p-3">
                <p class="text-xs text-muted-foreground">Concluídos</p>
                <p class="text-xl font-semibold tabular-nums">{{ summary.completed }}</p>
            </div>
            <div class="rounded-lg border border-border p-3">
                <p class="text-xs text-muted-foreground">Por corrigir</p>
                <p class="text-xl font-semibold tabular-nums">{{ summary.pending }}</p>
            </div>
            <div class="rounded-lg border border-border p-3">
                <p class="text-xs text-muted-foreground">Faltas</p>
                <p class="text-xl font-semibold tabular-nums">{{ summary.absent }}</p>
            </div>
            <div class="rounded-lg border border-border p-3">
                <p class="text-xs text-muted-foreground">Em revisão</p>
                <p class="text-xl font-semibold tabular-nums">{{ summary.under_review }}</p>
            </div>
        </div>

        <EmptyState v-if="students.length === 0" title="Nenhum aluno aplicável a esta avaliação." />

        <TableShell v-else>
            <template #head>
                <tr>
                    <th class="px-4 py-2.5 font-medium">Aluno</th>
                    <th class="px-4 py-2.5 font-medium">Estado</th>
                    <th class="px-4 py-2.5 font-medium">Resultado</th>
                    <th class="px-4 py-2.5 font-medium"></th>
                </tr>
            </template>
            <template #body>
                <tr v-for="student in students" :key="student.enrollment_id" class="hover:bg-muted/30">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1.5">
                                <span class="text-muted-foreground">{{ student.class_number ?? '—' }}</span>
                                <StudentAvatar :photo-url="student.photo_url" size="xs" />
                                <span class="font-medium">{{ student.name }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :class="stateBadgeClass[student.state_key] ?? 'bg-muted text-muted-foreground'">
                                {{ student.state_label }}<span v-if="student.state_detail"> ({{ student.state_detail }})</span>
                            </Badge>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground tabular-nums">{{ resultLabelFor(student) }}</td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="`/instruments/${instrument.ulid}?from=assessments`" class="text-sm text-primary hover:underline">{{ student.action_label }}</Link>
                        </td>
                    </tr>
            </template>
        </TableShell>

        <div v-if="nonApplicableStudents.length" class="space-y-2">
            <p class="text-xs text-muted-foreground">Não aplicável a esta avaliação — não entram no progresso nem contam como falta</p>
            <ul class="divide-y divide-dashed divide-border rounded-lg border border-dashed border-border text-sm text-muted-foreground">
                <li v-for="student in nonApplicableStudents" :key="student.enrollment_id" class="flex items-center gap-2 px-4 py-2">
                    <span>{{ student.class_number ?? '—' }}</span>
                    <StudentAvatar :photo-url="student.photo_url" size="xs" />
                    <span>{{ student.name }}</span>
                    <span class="ml-auto text-xs">Não aplicável</span>
                </li>
            </ul>
        </div>
    </div>
</template>
