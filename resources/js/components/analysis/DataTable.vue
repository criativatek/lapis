<script setup lang="ts">
import TableShell from '@/components/TableShell.vue';

/**
 * A mesma leitura do `LabelledBarChart` ao lado, em tabela: Categoria | N.º
 * de alunos | % — com uma linha «Total considerado». Usada sempre a par do
 * gráfico, nunca sozinha, para que a informação exista também em forma
 * tabular (acessibilidade e impressão).
 */

export type DataTableRow = {
    key: string;
    label: string;
    count: number;
    percent: string | null;
};

defineProps<{
    caption: string;
    rows: DataTableRow[];
    total: number;
}>();

function percentLabel(percent: string | null): string {
    if (percent === null) {
        return '—';
    }

    return `${Number(percent).toFixed(1).replace('.', ',')} %`;
}
</script>

<template>
    <TableShell class="print:break-inside-avoid">
        <template #head>
            <tr>
                <th scope="col" class="px-3 py-2 font-medium">{{ caption }}</th>
                <th scope="col" class="px-3 py-2 text-right font-medium">N.º de alunos</th>
                <th scope="col" class="px-3 py-2 text-right font-medium">%</th>
            </tr>
        </template>
        <template #body>
            <tr v-for="row in rows" :key="row.key">
                <td class="px-3 py-1.5">{{ row.label }}</td>
                <td class="px-3 py-1.5 text-right tabular-nums">{{ row.count }}</td>
                <td class="px-3 py-1.5 text-right tabular-nums">{{ percentLabel(row.percent) }}</td>
            </tr>
            <tr class="border-t border-border font-semibold">
                <td class="px-3 py-1.5">Total considerado</td>
                <td class="px-3 py-1.5 text-right tabular-nums">{{ total }}</td>
                <td class="px-3 py-1.5 text-right tabular-nums">100,0 %</td>
            </tr>
        </template>
    </TableShell>
</template>
