<script setup lang="ts">
/**
 * Estratégias e medidas, and the logbook beside them: what the teacher did, for
 * whom, and whether it is still open. Never part of the calculation — which is
 * why nothing on this card carries a grade.
 */
const entries = [
    {
        date: '14 fev',
        target: 'Mariana Sousa',
        action: 'Apoio à leitura de enunciados',
        status: 'Em curso',
        tone: 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300',
    },
    {
        date: '09 fev',
        target: 'Grupo de 4 alunos',
        action: 'Ficha de recuperação — equações',
        status: 'Concluída',
        tone: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
    },
    {
        date: '02 fev',
        target: 'Turma',
        action: 'Revisão antes do teste',
        status: 'Concluída',
        tone: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
    },
] as const;
</script>

<template>
    <div class="bg-card">
        <p
            class="border-b border-border/70 px-4 py-3 text-sm font-semibold tracking-tight"
        >
            Estratégias e medidas · 9.º B
        </p>

        <ul class="divide-y divide-border">
            <li
                v-for="entry in entries"
                :key="entry.date"
                class="flex gap-3 px-4 py-3"
            >
                <span
                    class="w-12 shrink-0 pt-0.5 text-[11px] text-muted-foreground tabular-nums"
                    >{{ entry.date }}</span
                >
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium">{{
                        entry.action
                    }}</span>
                    <span
                        class="mt-0.5 block text-[11px] text-muted-foreground"
                        >{{ entry.target }}</span
                    >
                </span>
                <span
                    class="h-fit shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                    :class="entry.tone"
                >
                    {{ entry.status }}
                </span>
            </li>
        </ul>

        <p
            class="border-t border-border/70 bg-muted/30 px-4 py-2.5 text-[11px] text-muted-foreground"
        >
            Fica no processo do aluno. Nunca entra no cálculo.
        </p>
    </div>
</template>
