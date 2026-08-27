<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ChevronRight, Plus, UserCheck } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type ClassRow = { ulid: string; label: string; subject: string; academic_year: string };

defineProps<{ classes: ClassRow[] }>();

// Presentation only — see results/Index.vue's `canCreateClass` for the same
// pattern and the reasoning (assessments/Index.vue's `canImportGrids`).
const canCreateClass = computed(() => usePage().props.modules.includes('classes'));
</script>

<template>
    <Head title="Autoavaliações" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading title="Autoavaliações" description="O aluno reflete por domínio — comparado com a avaliação, nunca somado a ela." />

        <div v-if="classes.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <UserCheck class="mx-auto mb-3 size-8 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">Ainda não tem turmas.</p>
            <Button v-if="canCreateClass" as-child class="mt-3">
                <Link href="/classes/create"><Plus class="size-4" /> Criar a primeira turma</Link>
            </Button>
        </div>

        <ul v-else class="divide-y divide-border overflow-hidden rounded-lg border border-border">
            <li v-for="schoolClass in classes" :key="schoolClass.ulid">
                <Link :href="`/classes/${schoolClass.ulid}/self-assessments`" class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-muted/30">
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
