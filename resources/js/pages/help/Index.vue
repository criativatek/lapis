<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Search as SearchIcon } from '@lucide/vue';
import { ref } from 'vue';
import HelpAssistantPanel from '@/components/ai/HelpAssistantPanel.vue';
import Heading from '@/components/Heading.vue';
import { search, show } from '@/routes/help';

type Article = {
    id: string;
    title: string;
    summary: string;
};

type Category = {
    category: string;
    articles: Article[];
};

type Reference = { id: string; title: string; url: string };

defineProps<{
    categories: Category[];
    // The assistant's state and its transient answer, both decided
    // server-side — see HelpController::assistantProps().
    ai: { available: boolean; reason: string | null };
    helpAnswer?: { question: string; text: string; references: Reference[]; sufficient: boolean } | null;
    helpAnswerError?: { message: string } | null;
}>();

const term = ref('');

function doSearch(): void {
    // Same "GET with a query param, server round-trip" shape
    // resources/js/pages/admin/Accounts.vue already uses for its own search
    // box — the article set is small enough that nothing more elaborate
    // (live filtering, debouncing) earns its cost here.
    router.get(search().url, { q: term.value });
}
</script>

<template>
    <Head title="Centro de Ajuda" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading title="Centro de Ajuda" description="Artigos sobre como utilizar o Lapispro." />

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

        <!-- Under the search box, above the articles: a question is what a
             teacher arrives with, and the article list is what they fall back
             to. Never a floating bubble, and nowhere but here. -->
        <HelpAssistantPanel :ai="ai" :answer="helpAnswer" :error="helpAnswerError" />

        <div v-if="categories.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <p class="text-sm text-muted-foreground">Ainda não há artigos de ajuda.</p>
        </div>

        <div v-else class="space-y-8">
            <section v-for="group in categories" :key="group.category" class="space-y-3">
                <h2 class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ group.category }}</h2>
                <ul class="divide-y divide-border rounded-lg border border-border">
                    <li v-for="article in group.articles" :key="article.id">
                        <Link
                            :href="show(article.id).url"
                            class="block px-4 py-3 hover:bg-muted/40"
                        >
                            <p class="text-sm font-medium">{{ article.title }}</p>
                            <p class="mt-0.5 text-sm text-muted-foreground">{{ article.summary }}</p>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
