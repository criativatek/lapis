<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { formatChangelogItem } from '@/lib/changelogFormat';

type Section = { category: string; items: string[] };
type Entry = { version: string; date: string; sections: Section[] };

defineProps<{
    entries: Entry[];
}>();
</script>

<template>
    <Head title="Novidades" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading title="Novidades" description="Histórico de alterações e funcionalidades do Lapispro." />

        <EmptyState v-if="entries.length === 0" title="Ainda não há registo de alterações." />

        <div v-else class="space-y-8">
            <article v-for="entry in entries" :key="entry.version" class="space-y-3">
                <div class="flex items-baseline gap-2">
                    <h2 class="text-base font-semibold">v{{ entry.version }}</h2>
                    <span class="text-sm text-muted-foreground">{{ entry.date }}</span>
                </div>

                <div v-for="section in entry.sections" :key="section.category" class="space-y-1.5">
                    <h3 class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ section.category }}</h3>
                    <ul class="list-disc space-y-1.5 pl-5 text-sm">
                        <li v-for="(item, index) in section.items" :key="index" v-html="formatChangelogItem(item)"></li>
                    </ul>
                </div>
            </article>
        </div>
    </div>
</template>
