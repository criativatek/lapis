<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Users } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type SchoolClass = {
    ulid: string;
    label: string;
    subject: string;
    academic_year: string;
    grade_level: string | null;
    status_label: string;
    students_count: number;
};

defineProps<{
    classes: SchoolClass[];
}>();
</script>

<template>
    <Head title="Turmas" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <div class="flex items-center justify-between">
            <Heading title="Turmas" description="As turmas que leciona neste ano letivo." />
            <Button as-child>
                <Link href="/classes/create"><Plus class="size-4" /> Nova turma</Link>
            </Button>
        </div>

        <div v-if="classes.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <p class="text-sm text-muted-foreground">Ainda não tem turmas. Crie a primeira.</p>
        </div>

        <div v-else class="grid gap-3 sm:grid-cols-2">
            <Link
                v-for="schoolClass in classes"
                :key="schoolClass.ulid"
                :href="`/classes/${schoolClass.ulid}`"
                class="rounded-lg border border-border p-4 transition-colors hover:border-primary/40"
            >
                <div class="flex items-start justify-between gap-2">
                    <span class="text-base font-semibold">{{ schoolClass.label }}</span>
                    <Badge variant="secondary">{{ schoolClass.status_label }}</Badge>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ schoolClass.subject }} · {{ schoolClass.academic_year }}<template v-if="schoolClass.grade_level"> · {{ schoolClass.grade_level }}</template>
                </p>
                <p class="mt-3 flex items-center gap-1.5 text-sm text-muted-foreground">
                    <Users class="size-4" /> {{ schoolClass.students_count }} alunos
                </p>
            </Link>
        </div>
    </div>
</template>
