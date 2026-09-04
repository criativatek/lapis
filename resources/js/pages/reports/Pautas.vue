<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ChevronRight, FileText, Plus } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type ClassRow = { ulid: string; label: string; subject: string; academic_year: string };

defineProps<{ classes: ClassRow[] }>();

// Presentation only — see results/Index.vue's `canCreateClass` for the same
// pattern and the reasoning (assessments/Index.vue's `canImportGrids`).
const canCreateClass = computed(() => usePage().props.modules.includes('classes'));
</script>

<template>
    <Head title="Pautas de classificações" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Link href="/reports" class="text-sm text-muted-foreground hover:underline">← Relatórios</Link>

        <Heading
            title="Pautas de classificações"
            description="A folha das classificações decididas, por turma — imprimível e exportável."
        />

        <EmptyState v-if="classes.length === 0" title="Ainda não tem turmas." :icon="FileText">
            <template v-if="canCreateClass" #action>
                <Button as-child>
                    <Link href="/classes/create"><Plus class="size-4" /> Criar a primeira turma</Link>
                </Button>
            </template>
        </EmptyState>

        <ul v-else class="divide-y divide-border overflow-hidden rounded-lg border border-border">
            <li v-for="schoolClass in classes" :key="schoolClass.ulid">
                <Link
                    :href="`/classes/${schoolClass.ulid}/report`"
                    class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-muted/30"
                >
                    <span>
                        <span class="font-medium">{{ schoolClass.label }}</span>
                        <span class="ml-2 text-sm text-muted-foreground">
                            {{ schoolClass.subject }} · {{ schoolClass.academic_year }}
                        </span>
                    </span>
                    <ChevronRight class="size-4 text-muted-foreground" />
                </Link>
            </li>
        </ul>
    </div>
</template>
