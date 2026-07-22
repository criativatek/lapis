<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, FileText } from '@lucide/vue';
import Heading from '@/components/Heading.vue';

type ClassRow = { ulid: string; label: string; subject: string; academic_year: string };

defineProps<{ classes: ClassRow[] }>();
</script>

<template>
    <Head title="Relatórios" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading title="Relatórios" description="Pauta de classificações por turma — imprimível e exportável." />

        <div v-if="classes.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <FileText class="mx-auto mb-3 size-8 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">Ainda não tem turmas.</p>
        </div>

        <ul v-else class="divide-y divide-border overflow-hidden rounded-lg border border-border">
            <li v-for="schoolClass in classes" :key="schoolClass.ulid">
                <Link :href="`/classes/${schoolClass.ulid}/report`" class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-muted/30">
                    <span>
                        <span class="font-medium">{{ schoolClass.label }}</span>
                        <span class="ml-2 text-sm text-muted-foreground">{{ schoolClass.subject }} · {{ schoolClass.academic_year }}</span>
                    </span>
                    <ChevronRight class="size-4 text-muted-foreground" />
                </Link>
            </li>
        </ul>
    </div>
</template>
