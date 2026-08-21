<script setup lang="ts">
import { Camera, FileUp } from '@lucide/vue';

/**
 * The class, as the teacher sees it after an import: numbers, names and the
 * photographs when the school had them.
 *
 * Initials stand in for the photographs here — the real screen shows faces, and
 * putting invented ones on a public page would be the one place this product
 * must not cut a corner.
 */
const students = [
    { number: '03', name: 'André Ferreira', initials: 'AF' },
    { number: '07', name: 'Diogo Antunes', initials: 'DA' },
    { number: '12', name: 'Beatriz Nunes', initials: 'BN' },
    { number: '18', name: 'Mariana Sousa', initials: 'MS' },
    { number: '21', name: 'Rita Melo', initials: 'RM' },
] as const;
</script>

<template>
    <div class="bg-card">
        <div
            class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-border/70 px-4 py-3"
        >
            <p class="text-sm font-semibold tracking-tight">
                9.º B · 24 alunos
            </p>
            <span
                class="ml-auto inline-flex items-center gap-1.5 rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground"
            >
                <FileUp aria-hidden="true" class="size-3" />
                Importado da pauta
            </span>
        </div>

        <ul class="divide-y divide-border">
            <li
                v-for="student in students"
                :key="student.number"
                class="flex items-center gap-3 px-4 py-2.5"
            >
                <span class="text-xs text-muted-foreground tabular-nums">{{
                    student.number
                }}</span>
                <span
                    aria-hidden="true"
                    class="flex size-7 items-center justify-center rounded-full bg-muted text-[11px] font-medium text-muted-foreground"
                >
                    {{ student.initials }}
                </span>
                <span class="min-w-0 truncate text-sm font-medium">{{
                    student.name
                }}</span>
            </li>
        </ul>

        <p
            class="flex items-center gap-2 border-t border-border/70 bg-muted/30 px-4 py-2.5 text-[11px] text-muted-foreground"
        >
            <Camera aria-hidden="true" class="size-3.5 shrink-0" />
            Mais 19 alunos. As fotografias entram no mesmo passo.
        </p>
    </div>
</template>
