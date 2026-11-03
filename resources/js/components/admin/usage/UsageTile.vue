<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { UsagePerson } from '@/types/usage';

type Group = { title?: string; people: UsagePerson[] };

const props = defineProps<{
    label: string;
    value: string | number;
    hint?: string;
    /** Quem foi contado; sem pessoas o cartão não muda. Um grupo com título serve para separar (ex.: «Chegaram» / «Ainda não»). */
    groups?: Group[];
    /** Ligação à ficha da pessoa; sem ela (ou sem ulid) o nome é texto simples. */
    personHref?: (ulid: string) => string;
}>();

const visibleGroups = computed(() => (props.groups ?? []).filter((group) => group.people.length > 0));
</script>

<template>
    <div class="rounded-xl border border-border bg-card p-4">
        <p class="text-sm text-muted-foreground">{{ label }}</p>
        <p class="mt-1 text-2xl font-semibold tabular-nums">{{ value }}</p>
        <p v-if="hint" class="mt-1 text-xs text-muted-foreground">{{ hint }}</p>
        <details v-if="visibleGroups.length > 0" class="mt-2 text-sm">
            <summary class="min-h-6 cursor-pointer text-xs font-medium text-primary">Ver quem</summary>
            <div v-for="(group, index) in visibleGroups" :key="index" class="mt-2">
                <p v-if="group.title" class="text-xs font-medium text-muted-foreground">{{ group.title }}</p>
                <ul class="mt-1 space-y-1.5">
                    <li v-for="person in group.people" :key="person.email" class="min-w-0">
                        <Link v-if="person.ulid && personHref" :href="personHref(person.ulid)" class="font-medium underline-offset-2 hover:underline">{{ person.name }}</Link>
                        <span v-else class="font-medium">{{ person.name }}</span>
                        <span v-if="person.internal" class="ml-1 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">Interno</span>
                        <span class="block break-all text-xs text-muted-foreground">{{ person.email }}</span>
                    </li>
                </ul>
            </div>
        </details>
    </div>
</template>
