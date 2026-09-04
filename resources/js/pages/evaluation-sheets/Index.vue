<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Plus, Table2 } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

/**
 * Pautas de Avaliação — choose the class to open its pauta on.
 *
 * Mirror of `results/Index.vue`: a simple class picker, gated by the same
 * `results` module the target route is already gated by server-side.
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

// Presentation only — the routes themselves are gated by `module:` on the
// server (same pattern as results/Index.vue's `canCreateClass`).
const canCreateClass = computed(() => usePage().props.modules.includes('classes'));
</script>

<template>
    <Head title="Pautas de Avaliação" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            title="Pautas de Avaliação"
            description="Consultar, guardar e rever a situação avaliativa da turma. Escolha a turma."
        />

        <EmptyState v-if="classes.length === 0" title="Ainda não tem turmas." :icon="Table2">
            <template v-if="canCreateClass" #action>
                <Button as-child>
                    <Link href="/classes/create"><Plus class="size-4" /> Criar a primeira turma</Link>
                </Button>
            </template>
        </EmptyState>

        <ul v-else class="divide-y divide-border overflow-hidden rounded-lg border border-border">
            <li v-for="row in classes" :key="row.ulid">
                <Link
                    :href="`/classes/${row.ulid}/pauta-avaliacao`"
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
