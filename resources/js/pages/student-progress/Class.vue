<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Users } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';

type StudentRow = {
    ulid: string;
    name: string;
    class_number: number | null;
    is_current: boolean;
    status_label: string;
    is_late_entry: boolean;
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string; academic_year: string; has_profile: boolean };
    students: StudentRow[];
}>();

/**
 * Everyone who was ever in the class, with the ones who left named as such.
 *
 * A picker built from the active roster alone would make a transferred
 * student's year unreachable, which is exactly the history this module exists
 * to show (§26, §58).
 */
const current = computed(() => props.students.filter((student) => student.is_current));
const former = computed(() => props.students.filter((student) => !student.is_current));
</script>

<template>
    <Head :title="`Acompanhamento — ${schoolClass.label}`" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            :title="`Acompanhamento do Aluno — ${schoolClass.label}`"
            :description="`${schoolClass.subject} · ${schoolClass.academic_year}. Escolha o aluno.`"
        />

        <p v-if="!schoolClass.has_profile" class="rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm text-amber-800 dark:text-amber-400">
            Esta turma ainda não tem perfil de avaliação, pelo que não existem resultados apurados.
        </p>

        <div v-if="students.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <Users class="mx-auto mb-3 size-8 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">Esta turma ainda não tem alunos inscritos.</p>
        </div>

        <template v-else>
            <ul class="divide-y divide-border overflow-hidden rounded-lg border border-border">
                <li v-for="student in current" :key="student.ulid">
                    <Link
                        :href="`/classes/${schoolClass.ulid}/evolucao/${student.ulid}`"
                        class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-muted/30"
                    >
                        <span class="min-w-0">
                            <span v-if="student.class_number" class="mr-2 text-xs tabular-nums text-muted-foreground">
                                {{ student.class_number }}
                            </span>
                            <span class="font-medium">{{ student.name }}</span>
                        </span>
                        <span v-if="student.is_late_entry" class="shrink-0 text-xs text-muted-foreground">
                            integrou após o início
                        </span>
                    </Link>
                </li>
            </ul>

            <section v-if="former.length > 0" class="space-y-2">
                <h2 class="text-sm font-medium text-muted-foreground">Já não integram a turma</h2>

                <ul class="divide-y divide-border overflow-hidden rounded-lg border border-dashed border-border">
                    <li v-for="student in former" :key="student.ulid">
                        <Link
                            :href="`/classes/${schoolClass.ulid}/evolucao/${student.ulid}`"
                            class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-muted/30"
                        >
                            <span class="min-w-0">
                                <span v-if="student.class_number" class="mr-2 text-xs tabular-nums text-muted-foreground">
                                    {{ student.class_number }}
                                </span>
                                <span class="font-medium">{{ student.name }}</span>
                            </span>
                            <span class="shrink-0 text-xs text-muted-foreground">{{ student.status_label }}</span>
                        </Link>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
