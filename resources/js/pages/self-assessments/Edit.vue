<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
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
}>();

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
