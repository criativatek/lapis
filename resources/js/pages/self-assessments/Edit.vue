<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import SelfAssessmentBlocks from '@/components/SelfAssessmentBlocks.vue';
import SelfAssessmentCriteriaPanel from '@/components/SelfAssessmentCriteriaPanel.vue';
import type { SelfAssessmentConfiguration, SelfAssessmentEarlierRound, SelfAssessmentQuestion } from '@/types/self-assessment';

const props = defineProps<{
    schoolClass: { ulid: string; label: string };
    period: { ulid: string; label: string };
    student: string;
    enrollmentUlid: string;
    /** The template the form was built from; sent back so a stale form is refused instead of half-saved. */
    template?: string | null;
    /** The wording of every question as it was shown; a question reworded since makes the form stale. */
    revision?: string | null;
    questions: SelfAssessmentQuestion[];
    earlierReflection: string | null;
    /** Self-assessments of this student in this period filled in under earlier criteria. Read-only. */
    earlierRounds?: SelfAssessmentEarlierRound[];
    status: string | null;
    /**
     * The criteria THIS form is built from — the class's own profile version,
     * not the latest of the profile, and not editable here.
     */
    configuration: SelfAssessmentConfiguration;
}>();

// Three maps by question id: the chosen level and the typed number for scale
// questions, the written answer for the rest. Each written answer stays its own —
// merging them would leave one field answering two questions.
const answers: Record<number, number | null> = {};
const texts: Record<number, string> = {};
const numbers: Record<number, string> = {};

for (const question of props.questions) {
    if (question.answer_kind === 'scale') {
        answers[question.id] = question.answer_level_id;
        numbers[question.id] = question.answer_number ?? '';

        continue;
    }

    texts[question.id] = question.answer_text ?? '';
}

const form = useForm<{ template: string | null; revision: string | null; answers: Record<number, number | null>; texts: Record<number, string>; numbers: Record<number, string> }>({
    template: props.template ?? null,
    revision: props.revision ?? null,
    answers,
    texts,
    numbers,
});

function submit(): void {
    form.post(`/classes/${props.schoolClass.ulid}/self-assessments/${props.period.ulid}/${props.enrollmentUlid}`);
}

function submittedOn(iso: string | null): string | null {
    return iso === null ? null : new Date(iso).toLocaleDateString('pt-PT', { day: 'numeric', month: 'long', year: 'numeric' });
}
</script>

<template>
    <Head :title="`Autoavaliação — ${student}`" />

    <div class="mx-auto w-full max-w-2xl space-y-4 p-4">
        <div>
            <Heading :title="`Autoavaliação — ${student}`" :description="`${schoolClass.label} · ${period.label}`" />
            <Link :href="`/classes/${schoolClass.ulid}/self-assessments/${period.ulid}`" class="inline-flex min-h-11 items-center text-sm text-muted-foreground hover:underline">
                ← Voltar à turma
            </Link>
        </div>

        <SelfAssessmentCriteriaPanel :configuration="configuration" context="form" />

        <section v-if="earlierRounds?.length" class="space-y-3" aria-labelledby="earlier-rounds-title" data-test="earlier-rounds">
            <h2 id="earlier-rounds-title" class="border-b border-border pb-1 text-sm font-semibold">Autoavaliação anterior deste período</h2>

            <article v-for="(round, index) in earlierRounds" :key="index" class="space-y-2 rounded-md border border-border bg-muted/30 p-3 text-sm">
                <p class="font-medium">Preenchida com os critérios anteriores: {{ round.criteria }}</p>
                <p v-if="submittedOn(round.submitted_at)" class="text-xs text-muted-foreground">Guardada a {{ submittedOn(round.submitted_at) }}</p>
                <dl class="space-y-2">
                    <div v-for="(answer, answerIndex) in round.answers" :key="answerIndex">
                        <dt class="text-xs text-muted-foreground">{{ answer.prompt }}</dt>
                        <dd class="whitespace-pre-line">{{ answer.value ?? '—' }}</dd>
                    </div>
                </dl>
            </article>
        </section>

        <form class="space-y-6" @submit.prevent="submit">
            <p v-if="form.errors.answers" role="alert" class="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                {{ form.errors.answers }}
            </p>

            <SelfAssessmentBlocks
                v-model:answers="form.answers"
                v-model:texts="form.texts"
                v-model:numbers="form.numbers"
                :questions="questions"
                :earlier-reflection="earlierReflection"
                :errors="form.errors"
                audience="teacher"
            />

            <div class="flex items-center justify-between gap-3">
                <p class="text-xs text-muted-foreground">A autoavaliação é comparada com a avaliação — nunca entra no cálculo.</p>
                <button
                    type="submit"
                    class="min-h-11 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Guardar autoavaliação
                </button>
            </div>
        </form>
    </div>
</template>
