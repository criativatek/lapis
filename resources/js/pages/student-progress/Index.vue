<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Plus, TrendingUp } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type ClassRow = {
    ulid: string;
    label: string;
    subject: string;
    academic_year: string;
    students: number;
    has_profile: boolean;
};

defineProps<{
    classes: ClassRow[];
}>();

// Presentation only — see results/Index.vue's `canCreateClass` for the same
// pattern and the reasoning (assessments/Index.vue's `canImportGrids`).
const canCreateClass = computed(() => usePage().props.modules.includes('classes'));
</script>

<template>
    <Head title="Acompanhamento do Aluno" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            title="Acompanhamento do Aluno"
            description="O percurso individual ao longo do ano letivo. Escolha a turma e depois o aluno."
        />

        <div v-if="classes.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <TrendingUp class="mx-auto mb-3 size-8 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">Ainda não tem turmas.</p>
            <Button v-if="canCreateClass" as-child class="mt-3">
                <Link href="/classes/create"><Plus class="size-4" /> Criar a primeira turma</Link>
            </Button>
        </div>

        <ul v-else class="divide-y divide-border overflow-hidden rounded-lg border border-border">
            <li v-for="row in classes" :key="row.ulid">
                <Link
                    :href="`/classes/${row.ulid}/evolucao`"
                    class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-muted/30"
                >
                    <span class="min-w-0">
                        <span class="font-medium">{{ row.label }}</span>
                        <span class="ml-2 text-xs text-muted-foreground">
                            {{ row.subject }} · {{ row.academic_year }}
                        </span>
                    </span>
                    <span class="shrink-0 text-xs text-muted-foreground">
                        <span v-if="!row.has_profile" class="text-amber-700 dark:text-amber-500">sem perfil</span>
                        <span v-else>{{ row.students }} {{ row.students === 1 ? 'aluno' : 'alunos' }}</span>
                    </span>
                </Link>
            </li>
        </ul>
    </div>
</template>
