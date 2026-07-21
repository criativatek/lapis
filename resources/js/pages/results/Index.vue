<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { BarChart3 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';

type ClassRow = {
    ulid: string;
    label: string;
    subject: string;
    academic_year: string;
    has_profile: boolean;
};

defineProps<{
    classes: ClassRow[];
}>();
</script>

<template>
    <Head title="Resultados" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading title="Resultados" description="Classificações calculadas por turma e período." />

        <div v-if="classes.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <BarChart3 class="mx-auto mb-3 size-8 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">Ainda não tem turmas.</p>
        </div>

        <ul v-else class="divide-y divide-border overflow-hidden rounded-lg border border-border">
            <li v-for="row in classes" :key="row.ulid">
                <Link :href="`/classes/${row.ulid}/results`" class="flex items-center justify-between px-4 py-3 hover:bg-muted/30">
                    <span>
                        <span class="font-medium">{{ row.label }}</span>
                        <span class="ml-2 text-xs text-muted-foreground">{{ row.subject }} · {{ row.academic_year }}</span>
                    </span>
                    <span v-if="!row.has_profile" class="text-xs text-amber-700">sem perfil</span>
                </Link>
            </li>
        </ul>
    </div>
</template>
