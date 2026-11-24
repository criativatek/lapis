<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Os separadores do elemento de avaliação — uma só definição para a grelha, os
 * resultados e os comentários. «Imprimir / PDF» não existe num elemento anulado.
 */
const props = defineProps<{
    ulid: string;
    active: 'grid' | 'results' | 'comments';
    cancelled?: boolean;
}>();

const tabs = computed(() => [
    { key: 'grid', label: 'Grelha de correção', href: `/instruments/${props.ulid}` },
    { key: 'results', label: 'Resultados', href: `/instruments/${props.ulid}/resultados` },
    { key: 'comments', label: 'Comentários', href: `/instruments/${props.ulid}/comentarios` },
]);
</script>

<template>
    <nav class="flex gap-1 border-b border-border" aria-label="Secções do elemento de avaliação">
        <template v-for="tab in tabs" :key="tab.key">
            <span
                v-if="tab.key === active"
                class="border-b-2 border-primary px-3 py-2 text-sm font-medium text-foreground"
                aria-current="page"
            >
                {{ tab.label }}
            </span>
            <Link
                v-else
                :href="tab.href"
                class="border-b-2 border-transparent px-3 py-2 text-sm text-muted-foreground hover:text-foreground"
            >
                {{ tab.label }}
            </Link>
        </template>
        <!-- A grelha como documento para imprimir. Um elemento anulado não tem nenhuma. -->
        <Link
            v-if="!cancelled"
            :href="`/instruments/${ulid}/impressao`"
            class="ml-auto px-3 py-2 text-sm text-muted-foreground hover:text-foreground"
        >
            Imprimir / PDF
        </Link>
    </nav>
</template>
