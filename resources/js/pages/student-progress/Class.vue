<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
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
/**
 * Pesquisa local (SUP-HWVGQE): os nomes já estão em memória nesta página,
 * por isso o filtro é instantâneo e por qualquer parte do nome ou pelo
 * número — o servidor só sabe procurar nomes completos (índice cego), e
 * quem tem muitas turmas não quer escrever nomes completos.
 */
const search = ref('');

function fold(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/gu, '')
        .toLowerCase()
        .trim();
}

function matches(student: StudentRow): boolean {
    const needle = fold(search.value);

    if (needle === '') {
        return true;
    }

    return fold(student.name).includes(needle) || String(student.class_number ?? '').includes(needle);
}

const current = computed(() => props.students.filter((student) => student.is_current && matches(student)));
const former = computed(() => props.students.filter((student) => !student.is_current && matches(student)));

const nothingMatches = computed(() => search.value !== '' && current.value.length === 0 && former.value.length === 0);
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

        <EmptyState v-if="students.length === 0" title="Esta turma ainda não tem alunos inscritos." :icon="Users" />

        <template v-else>
            <label class="grid gap-1 text-sm">
                <span class="text-xs font-medium text-muted-foreground">Procurar aluno</span>
                <input
                    v-model="search"
                    type="search"
                    placeholder="Nome ou número…"
                    class="h-9 w-full max-w-xs rounded-md border border-input bg-background px-3 text-sm"
                />
            </label>

            <EmptyState v-if="nothingMatches" :title="`Nenhum aluno corresponde a «${search}».`" />

            <ul v-if="current.length > 0" class="divide-y divide-border overflow-hidden rounded-lg border border-border">
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
