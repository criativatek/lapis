<script setup lang="ts">
import { computed } from 'vue';

type Row = Record<string, unknown>;

const props = defineProps<{
    sectionKey: string;
    data: Record<string, unknown> | null;
}>();

/**
 * The table that belongs beside a section's prose.
 *
 * NOT EVERY SECTION HAS ONE, and that is deliberate: a paragraph of narrative
 * followed by a table restating the same three numbers is padding. Only the
 * sections whose content is genuinely tabular — a distribution, a set of domain
 * averages, a breakdown of logbook entries — get one.
 *
 * NOTHING IS COMPUTED HERE. The figures arrive already shaped by the composer,
 * which got them from the source, which got them from the read model. This
 * formats and nothing more.
 */

function decimal(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return String(value).replace('.', ',');
}

function percentage(value: unknown): string {
    const text = decimal(value);

    return text === '—' ? text : `${text}%`;
}

const distributionRows = computed<Row[]>(() =>
    props.sectionKey === 'class_distribution' && Array.isArray(props.data?.rows) ? (props.data.rows as Row[]) : [],
);

const domainRows = computed<Row[]>(() =>
    props.sectionKey === 'domain_results' && Array.isArray(props.data?.domains) ? (props.data.domains as Row[]) : [],
);

const recordRows = computed<Row[]>(() =>
    (props.sectionKey === 'class_records' || props.sectionKey === 'student_records') && Array.isArray(props.data?.kinds)
        ? (props.data.kinds as Row[])
        : [],
);
</script>

<template>
    <!-- Wide content scrolls inside its own box; the page never scrolls sideways. -->
    <div v-if="distributionRows.length > 0" class="mt-3 overflow-x-auto">
        <table class="w-full min-w-[20rem] text-sm">
            <thead>
                <tr class="border-b border-border text-left text-xs text-muted-foreground">
                    <th class="py-1.5 pr-3 font-medium">Classificação</th>
                    <th class="py-1.5 pr-3 text-right font-medium">Alunos</th>
                    <th class="py-1.5 text-right font-medium">%</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in distributionRows" :key="index" class="border-b border-border/50 last:border-0">
                    <td class="py-1.5 pr-3">
                        {{ row.label ?? row.value ?? row.code }}
                        <span v-if="row.outside_scale" class="ml-1 text-xs text-muted-foreground">(fora da escala atual)</span>
                    </td>
                    <td class="py-1.5 pr-3 text-right tabular-nums">{{ row.count }}</td>
                    <td class="py-1.5 text-right tabular-nums text-muted-foreground">{{ percentage(row.percentage) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-if="domainRows.length > 0" class="mt-3 overflow-x-auto">
        <table class="w-full min-w-[26rem] text-sm">
            <thead>
                <tr class="border-b border-border text-left text-xs text-muted-foreground">
                    <th class="py-1.5 pr-3 font-medium">Domínio</th>
                    <th class="py-1.5 pr-3 text-right font-medium">Média</th>
                    <th class="py-1.5 pr-3 text-right font-medium">Positivas</th>
                    <th class="py-1.5 text-right font-medium">Sem resultado</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in domainRows" :key="index" class="border-b border-border/50 last:border-0">
                    <td class="py-1.5 pr-3">{{ row.label }}</td>
                    <td class="py-1.5 pr-3 text-right tabular-nums">{{ percentage(row.value) }}</td>
                    <td class="py-1.5 pr-3 text-right tabular-nums">
                        <template v-if="Number(row.placed) > 0">{{ row.succeeded }} / {{ row.placed }}</template>
                        <template v-else>—</template>
                    </td>
                    <td class="py-1.5 text-right tabular-nums text-muted-foreground">
                        {{ Number(row.students_without_result) > 0 ? row.students_without_result : '—' }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-if="recordRows.length > 0" class="mt-3 overflow-x-auto">
        <table class="w-full min-w-[22rem] text-sm">
            <thead>
                <tr class="border-b border-border text-left text-xs text-muted-foreground">
                    <th class="py-1.5 pr-3 font-medium">Tipo de registo</th>
                    <th class="py-1.5 pr-3 text-right font-medium">Registos</th>
                    <!-- The second unit, named. A record is not a student (§71). -->
                    <th class="py-1.5 text-right font-medium">Alunos envolvidos</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in recordRows" :key="index" class="border-b border-border/50 last:border-0">
                    <td class="py-1.5 pr-3">{{ row.label }}</td>
                    <td class="py-1.5 pr-3 text-right tabular-nums">{{ row.records }}</td>
                    <td class="py-1.5 text-right tabular-nums text-muted-foreground">
                        {{ Number(row.students_involved) > 0 ? row.students_involved : '—' }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
