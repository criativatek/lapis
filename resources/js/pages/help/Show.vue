<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { index, show } from '@/routes/help';

type Article = {
    id: string;
    title: string;
    summary: string;
    category: string;
    content: string[];
    keywords: string[];
    related: string[];
    contexts: string[];
    order: number;
    plan_note: string | null;
};

defineProps<{
    article: Article;
    related: Article[];
}>();
</script>

<template>
    <Head :title="article.title" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Link
            :href="index().url"
            class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft aria-hidden="true" class="size-3.5" />
            Centro de Ajuda
        </Link>

        <Heading :title="article.title" :description="article.summary" />

        <p
            v-if="article.plan_note"
            class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
        >
            {{ article.plan_note }}
        </p>

        <article class="space-y-4">
            <p v-for="(paragraph, paragraphIndex) in article.content" :key="paragraphIndex" class="leading-relaxed text-foreground">
                {{ paragraph }}
            </p>
        </article>

        <div v-if="related.length > 0" class="space-y-2 border-t border-border pt-6">
            <h2 class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Ver também</h2>
            <ul class="space-y-1.5">
                <li v-for="item in related" :key="item.id">
                    <Link :href="show(item.id).url" class="text-sm text-primary underline-offset-2 hover:underline">
                        {{ item.title }}
                    </Link>
                </li>
            </ul>
        </div>
    </div>
</template>
