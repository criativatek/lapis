<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The shared header for "Estrutura do Ano Letivo" (Fatia 5, §43-§53):
 * academic years and subjects are configuration, reached from Configuração
 * in the sidebar, not built from the header's context selectors. This is
 * deliberately a thin tab strip over the two EXISTING pages
 * (academic-years/Index.vue, subjects/Index.vue) rather than a new
 * aggregator page — same routes, same controllers, same policies, so
 * nothing about how either page works had to change (§56).
 *
 * Períodos has no tab of its own: a period is created and edited inside its
 * academic year's own form (academic-years/Form.vue), not as a standalone
 * catalog like Subject — surfacing it here again would duplicate that UI
 * rather than uniform it (§51).
 */
const active = computed(() => (usePage().url.startsWith('/subjects') ? 'subjects' : 'academic-years'));

const tabs = [
    { key: 'academic-years', label: 'Anos letivos', href: '/academic-years' },
    { key: 'subjects', label: 'Disciplinas', href: '/subjects' },
];
</script>

<template>
    <div class="space-y-1">
        <p class="text-xs text-muted-foreground">Estrutura do Ano Letivo — anos letivos, disciplinas e, dentro de cada ano, os seus períodos.</p>
        <nav class="flex gap-4 border-b border-border">
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="tab.href"
                class="border-b-2 px-1 pb-2 text-sm font-medium transition-colors"
                :class="active === tab.key ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'"
            >
                {{ tab.label }}
            </Link>
        </nav>
    </div>
</template>
