<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';

/**
 * A faithful reduction of the Classificações screen: the weighted average, what
 * Lapispro proposes from it, and the teacher's decision — in that reading order,
 * which is the order the real page uses (§10).
 *
 * The data is representative, not real, and every column shown here exists.
 * The one row that differs from the proposal carries the same amber marker the
 * application uses, because "the teacher may decide otherwise, and the record
 * keeps the reason" is the product, not a footnote.
 */

type PreviewRow = {
    number: string;
    name: string;
    average: string;
    proposal: string;
    decision: string;
    status: 'confirmed' | 'pending';
    overridden?: boolean;
};

const rows: readonly PreviewRow[] = [
    {
        number: '12',
        name: 'Beatriz Nunes',
        average: '78,4%',
        proposal: '4',
        decision: '4',
        status: 'confirmed',
    },
    {
        number: '07',
        name: 'Diogo Antunes',
        average: '61,0%',
        proposal: '3',
        decision: '3',
        status: 'confirmed',
    },
    {
        number: '18',
        name: 'Mariana Sousa',
        average: '49,2%',
        proposal: '2',
        decision: '3',
        status: 'confirmed',
        overridden: true,
    },
    {
        number: '03',
        name: 'André Ferreira',
        average: '—',
        proposal: '—',
        decision: 'Por confirmar',
        status: 'pending',
    },
];
</script>

<template>
    <div class="bg-card">
        <div
            class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border/70 px-4 py-3"
        >
            <p class="text-sm font-semibold tracking-tight">
                9.º B · Matemática
            </p>
            <span
                class="rounded-md bg-primary px-2 py-0.5 text-[11px] font-medium text-primary-foreground"
                >2.º Período</span
            >
            <span class="ml-auto text-[11px] text-muted-foreground">
                Escala 1 a 5
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[19rem] text-left text-sm">
                <caption class="sr-only">
                    Exemplo de proposta de classificação numa turma
                </caption>
                <thead
                    class="bg-muted/50 text-[11px] font-medium tracking-[0.06em] text-muted-foreground uppercase"
                >
                    <tr>
                        <th scope="col" class="px-4 py-2 font-medium">Aluno</th>
                        <th
                            scope="col"
                            class="hidden px-2 py-2 text-right font-medium sm:table-cell"
                        >
                            Média ponderada
                        </th>
                        <th
                            scope="col"
                            class="px-2 py-2 text-right font-medium"
                        >
                            Proposta
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-2 text-right font-medium"
                        >
                            Decisão
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="row in rows" :key="row.number">
                        <td class="px-4 py-2.5">
                            <span class="flex min-w-0 items-center gap-2">
                                <span
                                    class="text-xs text-muted-foreground tabular-nums"
                                    >{{ row.number }}</span
                                >
                                <span class="truncate font-medium">{{
                                    row.name
                                }}</span>
                            </span>
                        </td>
                        <td
                            class="hidden px-2 py-2.5 text-right text-muted-foreground tabular-nums sm:table-cell"
                        >
                            {{ row.average }}
                        </td>
                        <td class="px-2 py-2.5 text-right tabular-nums">
                            {{ row.proposal }}
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <span
                                v-if="row.status === 'confirmed'"
                                class="inline-flex items-center gap-1 font-semibold tabular-nums"
                            >
                                {{ row.decision }}
                                <CircleAlert
                                    v-if="row.overridden"
                                    aria-hidden="true"
                                    class="size-3 text-amber-500"
                                />
                                <span v-if="row.overridden" class="sr-only"
                                    >diferente da proposta, com justificação
                                    registada</span
                                >
                            </span>
                            <span
                                v-else
                                class="text-[11px] whitespace-nowrap text-muted-foreground"
                                >{{ row.decision }}</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p
            class="border-t border-border/70 bg-muted/30 px-4 py-2.5 text-[11px] leading-relaxed text-muted-foreground"
        >
            4 elementos de avaliação · 1 «Não aplicável» fora do denominador · a
            decisão diferente da proposta ficou registada com a justificação.
        </p>
    </div>
</template>
