<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Search as SearchIcon } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { search, show } from '@/routes/help';

type Article = {
    id: string;
    title: string;
    summary: string;
};

const props = defineProps<{ query: string; results: Article[] }>();

const term = ref(props.query);

function doSearch(): void {
    router.get(search().url, { q: term.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Pesquisar na Ajuda" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading title="Pesquisar na Ajuda" />

        <form class="flex items-center gap-2" @submit.prevent="doSearch">
            <div class="relative flex-1">
                <SearchIcon
                    aria-hidden="true"
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    v-model="term"
                    type="search"
                    placeholder="Pesquisar na ajuda…"
                    class="h-10 w-full rounded-md border border-input bg-transparent pl-9 pr-3 text-sm"
                />
            </div>
            <button
                type="submit"
                class="h-10 shrink-0 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:opacity-90"
            >
                Pesquisar
            </button>
        </form>

        <p v-if="query" class="text-sm text-muted-foreground">
            {{ results.length }} resultado{{ results.length === 1 ? '' : 's' }} para «{{ query }}»
        </p>

        <div v-if="query && results.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <p class="text-sm text-muted-foreground">Sem resultados para «{{ query }}».</p>
        </div>

        <ul v-else-if="results.length > 0" class="divide-y divide-border rounded-lg border border-border">
            <li v-for="article in results" :key="article.id">
                <Link :href="show(article.id).url" class="block px-4 py-3 hover:bg-muted/40">
                    <p class="text-sm font-medium">{{ article.title }}</p>
                    <p class="mt-0.5 text-sm text-muted-foreground">{{ article.summary }}</p>
                </Link>
            </li>
        </ul>
    </div>
</template>
