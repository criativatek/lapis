<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, NotebookPen } from '@lucide/vue';
import Heading from '@/components/Heading.vue';

type ClassRow = { ulid: string; label: string; subject: string; academic_year: string; records_count: number };

defineProps<{ classes: ClassRow[] }>();
</script>

<template>
    <Head title="Registos" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading title="Registos" description="O diário de bordo — observações, ocorrências e contactos, por turma." />

        <div v-if="classes.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <NotebookPen class="mx-auto mb-3 size-8 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">Ainda não tem turmas.</p>
        </div>

        <ul v-else class="divide-y divide-border overflow-hidden rounded-lg border border-border">
            <li v-for="schoolClass in classes" :key="schoolClass.ulid">
                <Link :href="`/classes/${schoolClass.ulid}/records`" class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-muted/30">
                    <span>
                        <span class="font-medium">{{ schoolClass.label }}</span>
                        <span class="ml-2 text-sm text-muted-foreground">{{ schoolClass.subject }} · {{ schoolClass.academic_year }}</span>
                    </span>
                    <span class="flex items-center gap-3">
                        <span class="text-xs text-muted-foreground">{{ schoolClass.records_count }} registos</span>
                        <ChevronRight class="size-4 text-muted-foreground" />
                    </span>
                </Link>
            </li>
        </ul>
    </div>
</template>
