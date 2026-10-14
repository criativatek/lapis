<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import SelfAssessmentBlocks from '@/components/SelfAssessmentBlocks.vue';
import type { SelfAssessmentQuestion } from '@/types/self-assessment';

const props = defineProps<{
    schoolClass: { ulid: string; label: string };
    period: { ulid: string; label: string };
    student: string;
    enrollmentUlid: string;
    questions: SelfAssessmentQuestion[];
    earlierReflection: string | null;
    status: string | null;
    /**
     * The class's OWN profile version and scale — not necessarily the version
     * the profile editor shows, since activating a newer one never moves a
     * class that already had one. Null for a class without a profile.
     */
    assessedBy: {
        profile: string | null;
        version: number;
        scale: string;
        scale_has_levels: boolean;
        /** The profile's active version, when it is not the class's own. */
        current_version: number | null;
    } | null;
}>();

// Said once, above the form, when a scale question has nothing to choose from:
// which version and which scale the class is actually on, and why.
const withoutLevels = computed(() => props.questions.some((question) => question.answer_kind === 'scale' && question.levels.length === 0));

// Two maps by question id: the chosen level for scale questions, the written
// answer for the rest. Each written answer stays its own — merging them would
// leave one field answering two questions.
const answers: Record<number, number | null> = {};
const texts: Record<number, string> = {};

for (const question of props.questions) {
    if (question.answer_kind === 'scale') {
        answers[question.id] = question.answer_level_id;

        continue;
    }

    texts[question.id] = question.answer_text ?? '';
}

const form = useForm<{ answers: Record<number, number | null>; texts: Record<number, string> }>({ answers, texts });

function submit(): void {
    form.post(`/classes/${props.schoolClass.ulid}/self-assessments/${props.period.ulid}/${props.enrollmentUlid}`);
}
</script>

<template>
    <Head :title="`Autoavaliação — ${student}`" />

    <div class="mx-auto w-full max-w-2xl space-y-4 p-4">
        <div>
            <Heading :title="`Autoavaliação — ${student}`" :description="`${schoolClass.label} · ${period.label}`" />
            <Link :href="`/classes/${schoolClass.ulid}/self-assessments/${period.ulid}`" class="text-sm text-muted-foreground hover:underline">
                ← Voltar à turma
            </Link>
        </div>

        <div
            v-if="assessedBy && withoutLevels"
            role="note"
            class="space-y-1 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
            data-test="assessed-by"
        >
            <p>
                Esta turma é avaliada pela versão {{ assessedBy.version }}<template v-if="assessedBy.profile"> do perfil «{{ assessedBy.profile }}»</template>, na escala «{{ assessedBy.scale }}».
                <template v-if="!assessedBy.scale_has_levels">
                    Essa escala não tem níveis, por isso as perguntas por domínio e a classificação proposta não têm opções. As respostas escritas podem ser guardadas.
                </template>
                <template v-else>
                    As perguntas sem opções vêm de uma configuração anterior da turma e mantêm-se porque já há autoavaliações registadas com elas.
                </template>
            </p>
            <p v-if="assessedBy.current_version !== null">
                O perfil tem uma versão mais recente ({{ assessedBy.current_version }}), mas esta turma continua na versão {{ assessedBy.version }}: ativar uma versão nova não altera as turmas que já estavam associadas.
            </p>
        </div>

        <form class="space-y-6" @submit.prevent="submit">
            <SelfAssessmentBlocks
                v-model:answers="form.answers"
                v-model:texts="form.texts"
                :questions="questions"
                :earlier-reflection="earlierReflection"
            />

            <div class="flex items-center justify-between gap-3">
                <p class="text-xs text-muted-foreground">A autoavaliação é comparada com a avaliação — nunca entra no cálculo.</p>
                <button
                    type="submit"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Guardar autoavaliação
                </button>
            </div>
        </form>
    </div>
</template>
