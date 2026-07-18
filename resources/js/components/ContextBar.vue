<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    CalendarRange,
    GraduationCap,
    Layers,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import type { LucideIcon } from '@lucide/vue';

/**
 * The header scope selectors — academic year, subject, grade level, class,
 * period. Choosing here sets the working context for every screen (§9, and the
 * "Seletores de contexto no cabeçalho" section of the navigation doc), so this
 * is an architectural element, not decoration.
 *
 * Every selector is empty until the academic model lands in Fase 1. It renders
 * the structure with an em-dash and stays disabled rather than inventing schools
 * or classes that do not exist.
 */
const page = usePage();
const scope = computed(() => page.props.scope);

type Selector = { key: string; icon: LucideIcon; label: string; value: string | null };

const selectors = computed<Selector[]>(() => [
    { key: 'academicYear', icon: CalendarRange, label: 'Ano letivo', value: scope.value.academicYear },
    { key: 'subject', icon: BookOpen, label: 'Disciplina', value: scope.value.subject },
    { key: 'gradeLevel', icon: GraduationCap, label: 'Ano', value: scope.value.gradeLevel },
    { key: 'class', icon: Users, label: 'Turma', value: scope.value.class },
    { key: 'period', icon: Layers, label: 'Período', value: scope.value.period },
]);
</script>

<template>
    <div class="flex min-w-0 flex-1 items-center gap-1.5 overflow-x-auto">
        <button
            v-for="selector in selectors"
            :key="selector.key"
            type="button"
            disabled
            :title="`${selector.label} — disponível na próxima fase`"
            class="flex shrink-0 items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-1.5 text-sm text-muted-foreground disabled:cursor-not-allowed"
        >
            <component :is="selector.icon" class="size-3.5 shrink-0 opacity-70" />
            <span class="hidden font-medium text-foreground/70 sm:inline">{{ selector.label }}:</span>
            <span class="whitespace-nowrap">{{ selector.value ?? '—' }}</span>
        </button>
    </div>
</template>
