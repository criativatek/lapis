<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleHelp } from '@lucide/vue';
import { show } from '@/routes/help';

/**
 * "Precisa de ajuda?" — up to a few Centro de Ajuda links relevant to the
 * page it sits on (A2, Onboarding & Help).
 *
 * THE ARTICLES ARE ALWAYS A PROP, NEVER FETCHED HERE. The containing page's
 * controller resolves them server-side, through `HelpCenter::forContext()`,
 * and passes them down as an ordinary Inertia prop — the same "the
 * controller decides, the component renders" split every other page in this
 * app already follows. This component makes no request of its own.
 *
 * RENDERS NOTHING when there are no articles — a page with no relevant help
 * content shows no empty "Precisa de ajuda?" box. Used deliberately on only
 * three pages (assessment-profiles, instruments, roster-import preview);
 * elsewhere, a single one-line explainer belongs on the existing `help`
 * prop already established on `KpiCard.vue` (a native `title` tooltip), not
 * on a second mechanism.
 */

type Article = {
    id: string;
    title: string;
    summary: string;
};

withDefaults(defineProps<{ articles?: Article[] }>(), { articles: () => [] });
</script>

<template>
    <div
        v-if="articles.length > 0"
        class="rounded-lg border border-dashed border-border p-4"
    >
        <h2 class="flex items-center gap-1.5 text-sm font-semibold">
            <CircleHelp aria-hidden="true" class="size-4 text-muted-foreground" />
            Precisa de ajuda?
        </h2>
        <ul class="mt-2 space-y-2">
            <li v-for="article in articles.slice(0, 3)" :key="article.id">
                <Link
                    :href="show(article.id).url"
                    class="text-sm font-medium text-primary underline-offset-2 hover:underline"
                >
                    {{ article.title }}
                </Link>
                <p class="text-xs text-muted-foreground">{{ article.summary }}</p>
            </li>
        </ul>
    </div>
</template>
