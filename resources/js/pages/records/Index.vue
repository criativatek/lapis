<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ChevronRight, NotebookPen, Plus } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type ClassRow = { ulid: string; label: string; subject: string; academic_year: string; records_count: number };

defineProps<{ classes: ClassRow[] }>();

// Presentation only — see results/Index.vue's `canCreateClass` for the same
// pattern and the reasoning (assessments/Index.vue's `canImportGrids`).
const canCreateClass = computed(() => usePage().props.modules.includes('classes'));
</script>

<template>
    <Head title="Registos" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading title="Registos" description="O diário de bordo — observações, ocorrências e contactos, por turma." />

        <EmptyState v-if="classes.length === 0" title="Ainda não tem turmas." :icon="NotebookPen">
            <template v-if="canCreateClass" #action>
                <Button as-child>
                    <Link href="/classes/create"><Plus class="size-4" /> Criar a primeira turma</Link>
                </Button>
            </template>
        </EmptyState>

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
