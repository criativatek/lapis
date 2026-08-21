<script setup lang="ts">
import { FileText, Lock } from '@lucide/vue';

/**
 * A report document mid-draft: sections composed from data the teacher already
 * registered, each one editable, and a finalisation that closes it for good.
 */
const sections = [
    { title: 'Caracterização da turma', state: 'Gerado' },
    { title: 'Desempenho por domínio', state: 'Gerado' },
    { title: 'Alunos a acompanhar', state: 'Editado' },
    { title: 'Medidas aplicadas no período', state: 'Gerado' },
] as const;
</script>

<template>
    <div class="bg-card">
        <div
            class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-border/70 px-4 py-3"
        >
            <p class="text-sm font-semibold tracking-tight">
                Relatório · 2.º Período
            </p>
            <span
                class="ml-auto inline-flex items-center gap-1.5 rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground"
            >
                Rascunho
            </span>
        </div>

        <ol class="divide-y divide-border">
            <li
                v-for="(section, index) in sections"
                :key="section.title"
                class="flex items-center gap-3 px-4 py-2.5"
            >
                <span
                    aria-hidden="true"
                    class="text-[11px] text-muted-foreground tabular-nums"
                    >{{ String(index + 1).padStart(2, '0') }}</span
                >
                <span class="min-w-0 flex-1 truncate text-sm">{{
                    section.title
                }}</span>
                <span
                    class="shrink-0 rounded border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground"
                    >{{ section.state }}</span
                >
            </li>
        </ol>

        <div
            class="flex flex-wrap items-center gap-2 border-t border-border/70 bg-muted/30 px-4 py-3"
        >
            <span
                class="inline-flex items-center gap-1.5 rounded-md border border-border bg-background/70 px-2 py-1 text-[11px] font-medium"
            >
                <FileText aria-hidden="true" class="size-3" />
                PDF
            </span>
            <span
                class="inline-flex items-center gap-1.5 rounded-md border border-border bg-background/70 px-2 py-1 text-[11px] font-medium"
            >
                <FileText aria-hidden="true" class="size-3" />
                Word
            </span>
            <span
                class="ml-auto inline-flex items-center gap-1.5 text-[11px] text-muted-foreground"
            >
                <Lock aria-hidden="true" class="size-3" />
                Finalizar fixa o documento
            </span>
        </div>
    </div>
</template>
