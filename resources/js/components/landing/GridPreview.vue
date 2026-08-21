<script setup lang="ts">
/**
 * The correction grid — the screen a teacher actually spends the afternoon in.
 *
 * The three highlighted cells are the ones the callouts underneath explain, and
 * they are highlighted with a ring rather than a fill so the cell still reads
 * as a cell. Representative data; every state shown is one the grid supports.
 */

type Cell = {
    display: string;
    /** Marks the cells the section's callouts point at. */
    note?: 'absent' | 'notApplicable' | 'pending';
};

type GridRow = {
    number: string;
    name: string;
    cells: readonly Cell[];
};

const columns = ['Teste 1', 'Trabalho', 'Apresentação', 'Ficha 2'] as const;

const rows: readonly GridRow[] = [
    {
        number: '12',
        name: 'Beatriz Nunes',
        cells: [
            { display: '84' },
            { display: '78' },
            { display: '90' },
            { display: '81' },
        ],
    },
    {
        number: '07',
        name: 'Diogo Antunes',
        cells: [
            { display: '62' },
            { display: 'Aus', note: 'absent' },
            { display: '70' },
            { display: '58' },
        ],
    },
    {
        number: '18',
        name: 'Mariana Sousa',
        cells: [
            { display: '48' },
            { display: '55' },
            { display: 'N/A', note: 'notApplicable' },
            { display: '52' },
        ],
    },
    {
        number: '03',
        name: 'André Ferreira',
        cells: [
            { display: 'Por avaliar', note: 'pending' },
            { display: '66' },
            { display: '72' },
            { display: '61' },
        ],
    },
    {
        number: '21',
        name: 'Rita Melo',
        cells: [
            { display: '91' },
            { display: '88' },
            { display: 'Disp' },
            { display: '94' },
        ],
    },
];

const NOTE_RING: Record<NonNullable<Cell['note']>, string> = {
    absent: 'ring-2 ring-inset ring-amber-400/80 bg-amber-50/60 dark:bg-amber-950/30',
    notApplicable:
        'ring-2 ring-inset ring-sky-400/80 bg-sky-50/60 dark:bg-sky-950/30',
    pending:
        'ring-2 ring-inset ring-violet-400/80 bg-violet-50/60 dark:bg-violet-950/30',
};
</script>

<template>
    <div class="bg-card">
        <div
            class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-border/70 px-4 py-3"
        >
            <p class="text-sm font-semibold tracking-tight">
                Grelha de correção · 9.º B
            </p>
            <span class="text-[11px] text-muted-foreground">
                Conhecimentos e capacidades · 60%
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[34rem] text-left text-sm">
                <caption class="sr-only">
                    Exemplo de grelha de correção com os estados que uma célula
                    pode assumir
                </caption>
                <thead
                    class="bg-muted/50 text-[11px] font-medium tracking-[0.06em] text-muted-foreground uppercase"
                >
                    <tr>
                        <th scope="col" class="px-4 py-2 font-medium">Aluno</th>
                        <th
                            v-for="column in columns"
                            :key="column"
                            scope="col"
                            class="px-3 py-2 text-center font-medium"
                        >
                            {{ column }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="row in rows" :key="row.number">
                        <th
                            scope="row"
                            class="px-4 py-2.5 text-left font-normal whitespace-nowrap"
                        >
                            <span class="flex items-center gap-2">
                                <span
                                    class="text-xs text-muted-foreground tabular-nums"
                                    >{{ row.number }}</span
                                >
                                <span class="font-medium">{{ row.name }}</span>
                            </span>
                        </th>
                        <td
                            v-for="(cell, index) in row.cells"
                            :key="index"
                            class="p-1"
                        >
                            <span
                                class="flex h-8 items-center justify-center rounded-md text-center text-[13px] tabular-nums"
                                :class="
                                    cell.note
                                        ? NOTE_RING[cell.note]
                                        : 'bg-muted/40'
                                "
                            >
                                {{ cell.display }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p
            class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-t border-border/70 bg-muted/30 px-4 py-2.5 text-[11px] text-muted-foreground"
        >
            <span
                >Enter e ↓ descem na coluna. Guardar não fecha a correção.</span
            >
            <span>1 célula por resolver</span>
        </p>
    </div>
</template>
