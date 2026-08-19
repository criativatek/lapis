<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { PieChart } from '@lucide/vue';
import Heading from '@/components/Heading.vue';

/**
 * Acompanhamento > Turma — choose whose year to read.
 *
 * THE ROUTE IS STILL `results.index`, and that is deliberate. This picker did
 * not change what it does; what changed is where it sends the teacher. It used
 * to open the operational grid, and now it opens the class reading — the page
 * that answers «como está esta turma?». The grid is one click further in, from
 * that page and from the dashboard, and is the same screen it always was.
 *
 * A route name is not a label. Renaming it would move a URL for a menu heading,
 * which is a worse trade than a name that reads slightly historically here.
 */

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
    <Head title="Acompanhamento da Turma" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            title="Acompanhamento da Turma"
            description="Desempenho e evolução ao longo do ano letivo. Escolha a turma."
        />

        <div v-if="classes.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <PieChart class="mx-auto mb-3 size-8 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">Ainda não tem turmas.</p>
        </div>

        <ul v-else class="divide-y divide-border overflow-hidden rounded-lg border border-border">
            <li v-for="row in classes" :key="row.ulid">
                <Link
                    :href="`/classes/${row.ulid}/results/estatistica`"
                    class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-muted/30"
                >
                    <span class="min-w-0">
                        <span class="font-medium">{{ row.label }}</span>
                        <span class="ml-2 text-xs text-muted-foreground">{{ row.subject }} · {{ row.academic_year }}</span>
                    </span>
                    <span v-if="!row.has_profile" class="shrink-0 text-xs text-amber-700 dark:text-amber-500">sem perfil</span>
                </Link>
            </li>
        </ul>
    </div>
</template>
