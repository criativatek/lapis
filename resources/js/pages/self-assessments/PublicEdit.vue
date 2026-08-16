<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import SelfAssessmentBlocks from '@/components/SelfAssessmentBlocks.vue';
import AuthSimpleLayout from '@/layouts/auth/AuthSimpleLayout.vue';
import type { SelfAssessmentQuestion } from '@/types/self-assessment';

defineOptions({ layout: AuthSimpleLayout });

const props = defineProps<{
    schoolClass: { ulid: string; label: string };
    period: { ulid: string; label: string };
    student: string;
    questions: SelfAssessmentQuestion[];
    earlierReflection: string | null;
    status: string | null;
    submitUrl: string;
}>();

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
    // Posts back to the exact signed URL this page was opened with — never a
    // route built from the path params alone, which would be missing the
    // signature and get rejected.
    form.post(props.submitUrl);
}
</script>

<template>
    <Head :title="`Autoavaliação — ${student}`" />

    <div class="w-full space-y-4">
        <div class="text-center">
            <p class="text-sm text-muted-foreground">{{ schoolClass.label }} · {{ period.label }}</p>
        </div>

        <form class="space-y-6" @submit.prevent="submit">
            <SelfAssessmentBlocks
                v-model:answers="form.answers"
                v-model:texts="form.texts"
                :questions="questions"
                :earlier-reflection="earlierReflection"
            />

            <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-muted-foreground">A tua autoavaliação é comparada com a avaliação do professor, nunca entra no cálculo.</p>
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
