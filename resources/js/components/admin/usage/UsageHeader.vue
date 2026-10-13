<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { commonQuery, withQuery } from '@/lib/usageFormat';
import type { UsageFilters } from '@/types/usage';

/**
 * Título, frase de apoio e separadores. Os separadores levam os filtros comuns
 * na query: mudar de página não pode deitar fora o período que se estava a ler.
 */
const props = defineProps<{ filters: UsageFilters; active: 'overview' | 'users' | 'ranking' }>();

const tabs = computed(() => {
    const query = commonQuery(props.filters);

    return [
        { key: 'overview', label: 'Visão geral', href: withQuery('/admin/usage', query) },
        { key: 'users', label: 'Acompanhamento', href: withQuery('/admin/usage/users', query) },
        { key: 'ranking', label: 'Ranking', href: withQuery('/admin/usage/ranking', query) },
    ];
});
</script>

<template>
    <header class="space-y-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Utilização e acompanhamento</h1>
            <p class="text-sm text-muted-foreground">
                Painel interno sobre a adoção do Lapispro. Mostra regularidade e ações concluídas — não avalia professores.
            </p>
        </div>

        <nav aria-label="Secções do painel" class="flex gap-1 overflow-x-auto border-b border-border">
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="tab.href"
                :aria-current="active === tab.key ? 'page' : undefined"
                class="flex min-h-11 items-center whitespace-nowrap border-b-2 px-3 text-sm md:min-h-10"
                :class="active === tab.key ? 'border-primary font-medium text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'"
            >
                {{ tab.label }}
            </Link>
        </nav>
    </header>
</template>
