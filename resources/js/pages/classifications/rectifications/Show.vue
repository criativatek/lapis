<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import RectificationHistory from '@/components/classifications/RectificationHistory.vue';
import RectifyDialog from '@/components/classifications/RectifyDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import TableShell from '@/components/TableShell.vue';
import { decidedScaleNote, decidedValueText } from '@/lib/decidedValue';
import { rectificationDate } from '@/lib/rectification';
import type { RectificationReasonOption, RectificationScale, RectificationState } from '@/lib/rectification';

/**
 * A tabela de um período encerrado, vista pelo responsável que não leciona a turma.
 *
 * A lista de campos é a do servidor e é deliberadamente curta: número, nome,
 * classificação publicada (e a data) e o que se pode fazer com ela. Nada de
 * médias, propostas, autoavaliação, elementos, pontuações, observações,
 * assiduidade ou fotografias — esta página não tem como as mostrar porque o
 * servidor não as envia.
 */
type Decision = {
    code: string;
    label: string | null;
    scale_level_id: number | null;
    is_percentage?: boolean;
    scale_name?: string | null;
};

type Row = {
    enrollment_ulid: string;
    class_number: number | null;
    name: string;
    classification: {
        ulid: string;
        published_at: string | null;
        decision: Decision | null;
        rectification: RectificationState;
    };
};

const props = defineProps<{
    schoolClass: {
        ulid: string;
        label: string;
        subject: string | null;
        academic_year: string;
        scale_name: string | null;
        decision_label: string;
        classifies_by_level: boolean;
        levels: { id: number; code: string; label: string }[];
        min_value: string | null;
        max_value: string | null;
    };
    period: { ulid: string; label: string };
    rectificationReasons: RectificationReasonOption[];
    rows: Row[];
}>();

const basePath = computed(() => `/classes/${props.schoolClass.ulid}/classifications/${props.period.ulid}`);

/** A escala em que se retifica é a mesma em que se decide. */
const rectifyScale = computed<RectificationScale>(() => ({
    label: props.schoolClass.decision_label,
    classifies_by_level: props.schoolClass.classifies_by_level,
    levels: props.schoolClass.levels,
    min_value: props.schoolClass.min_value,
    max_value: props.schoolClass.max_value,
}));

/** Um nível lê-se pelo código; um valor, como número ou percentagem. */
function decisionText(decision: Decision): string {
    if (decision.scale_level_id !== null) {
        return decision.code;
    }

    return decidedValueText(String(Number(decision.code)).replace('.', ','), decision.is_percentage) ?? '—';
}

function decisionTitle(decision: Decision): string {
    const text = decision.label === null ? decisionText(decision) : `${decision.code} — ${decision.label}`;
    const note = decidedScaleNote(decision.scale_name);

    return note === null ? text : `${text} — ${note}`;
}

function rectifyCurrent(decision: Decision): { text: string; levelId: number | null; value: string | null } {
    return {
        text: decisionTitle(decision),
        levelId: decision.scale_level_id,
        // Um valor decidido noutra escala não é um valor desta: não se compara.
        value: decision.scale_level_id === null && !decision.scale_name ? decision.code : null,
    };
}

function submitUrl(row: Row): string {
    return `${basePath.value}/${row.enrollment_ulid}/rectify`;
}

function historyUrl(row: Row): string {
    return `/classes/${props.schoolClass.ulid}/classifications/${row.classification.ulid}/rectification`;
}

function publishedOn(row: Row): string {
    return row.classification.published_at === null ? '—' : rectificationDate(row.classification.published_at);
}
</script>

<template>
    <Head :title="`Retificações — ${schoolClass.label}`" />

    <div class="space-y-4 p-4">
        <div>
            <Heading
                :title="`${schoolClass.label} — ${period.label}`"
                :description="[schoolClass.subject, schoolClass.academic_year].filter(Boolean).join(' · ')"
            />
            <Link
                href="/retificacoes"
                class="-mt-6 inline-flex min-h-11 items-center text-sm text-muted-foreground hover:underline md:min-h-9"
            >
                ← Voltar a Retificações
            </Link>
        </div>

        <p class="text-sm text-muted-foreground" data-testid="privacy-notice">
            Esta página mostra apenas as classificações publicadas. A consulta fica registada.
        </p>

        <EmptyState v-if="rows.length === 0" title="Sem classificações publicadas neste período." />

        <TableShell v-else>
            <template #head>
                <tr>
                    <th class="px-3 py-2 font-medium">Nº</th>
                    <th class="px-3 py-2 font-medium">Aluno</th>
                    <th class="px-3 py-2 text-right font-medium">{{ schoolClass.decision_label }}</th>
                    <th class="hidden px-3 py-2 font-medium sm:table-cell">Publicada em</th>
                    <th class="px-3 py-2 text-right font-medium">Ação</th>
                </tr>
            </template>
            <template #body>
                <tr v-for="row in rows" :key="row.enrollment_ulid" class="hover:bg-muted/20" data-testid="rectification-row">
                    <td class="px-3 py-2 text-muted-foreground tabular-nums">{{ row.class_number ?? '—' }}</td>
                    <td class="px-3 py-2">
                        <span class="font-medium">{{ row.name }}</span>
                        <span class="block text-xs text-muted-foreground sm:hidden">{{ publishedOn(row) }}</span>
                    </td>
                    <td class="px-3 py-2 text-right font-semibold whitespace-nowrap tabular-nums">
                        <span v-if="row.classification.decision" :title="decisionTitle(row.classification.decision)">{{ decisionText(row.classification.decision) }}</span>
                        <span v-else class="text-muted-foreground">—</span>
                        <RectificationHistory
                            :count="row.classification.rectification.count"
                            :history-url="historyUrl(row)"
                            :student-name="row.name"
                        />
                    </td>
                    <td class="hidden px-3 py-2 text-muted-foreground tabular-nums sm:table-cell">{{ publishedOn(row) }}</td>
                    <td class="px-3 py-2 text-right">
                        <RectifyDialog
                            v-if="row.classification.decision"
                            :rectification="row.classification.rectification"
                            :scale="rectifyScale"
                            :reasons="rectificationReasons"
                            :current="rectifyCurrent(row.classification.decision)"
                            :student-name="row.name"
                            :submit-url="submitUrl(row)"
                            :details-url="historyUrl(row)"
                        />
                    </td>
                </tr>
            </template>
        </TableShell>
    </div>
</template>
