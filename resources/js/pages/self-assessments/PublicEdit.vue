<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import SelfAssessmentBlocks from '@/components/SelfAssessmentBlocks.vue';
import SelfAssessmentReadOnlyNotice from '@/components/SelfAssessmentReadOnlyNotice.vue';
import AuthSimpleLayout from '@/layouts/auth/AuthSimpleLayout.vue';
import type { SelfAssessmentCriteria, SelfAssessmentQuestion } from '@/types/self-assessment';

defineOptions({ layout: AuthSimpleLayout });

const props = defineProps<{
    schoolClass: { ulid: string; label: string };
    period: { ulid: string; label: string };
    student: string;
    /** The template the form was built from; sent back so a stale form is refused instead of half-saved. */
    template?: string | null;
    /** The wording of every question as it was shown; a question reworded since makes the form stale. */
    revision?: string | null;
    /** What the subject is assessed on: the domains with their weights and the scale. No profile, no version. */
    criteria?: SelfAssessmentCriteria | null;
    /**
     * `previous`: the teacher has not started a new self-assessment since the class's criteria changed,
     * so these are the criteria this one was started with — not the class's today. Absent = `current`.
     */
    criteriaState?: 'current' | 'previous';
    /** Containment mode: the form is shown with what is saved, and nothing can be saved. */
    readOnly?: boolean;
    /** The student already answered this period under earlier criteria. The answer itself is never sent. */
    hasEarlierRound?: boolean;
    questions: SelfAssessmentQuestion[];
    earlierReflection: string | null;
    status: string | null;
    submitUrl: string;
}>();

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

function weight(value: string): string {
    return `${value.replace('.', ',')}%`;
}

function submit(): void {
    if (props.readOnly) {
        return;
    }

    // Posts back to the exact signed URL this page was opened with — never a
    // route built from the path params alone, which would be missing the
    // signature and get rejected.
    form.post(props.submitUrl);
}
</script>

<template>
    <Head :title="`Autoavaliação — ${student}`" />

    <div class="w-full space-y-4">
        <div class="space-y-3 text-center">
            <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Autoavaliação</p>

            <div class="rounded-lg border border-border bg-muted/30 p-3 text-sm">
                <p>
                    Esta autoavaliação corresponde a:
                    <span class="font-medium">{{ student }}</span>
                </p>
                <p class="mt-1 text-xs text-muted-foreground">Confirma que este é o teu nome antes de continuar.</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Se este não é o teu nome, não preenchas esta autoavaliação e avisa o teu professor.
                </p>
            </div>

            <p class="text-sm text-muted-foreground">{{ schoolClass.label }} · {{ period.label }}</p>
        </div>

        <SelfAssessmentReadOnlyNotice v-if="readOnly" audience="student" />

        <section v-if="criteria" class="space-y-2 rounded-lg border border-border p-3 text-sm" aria-labelledby="how-assessed-title" data-test="how-assessed">
            <h2 id="how-assessed-title" class="font-semibold">{{ criteriaState === 'previous' ? 'Critérios desta autoavaliação' : 'Como é feita a avaliação nesta disciplina' }}</h2>
            <p v-if="criteriaState === 'previous'" data-test="criteria-previous">Esta autoavaliação mantém os critérios com que foi iniciada.</p>
            <ul v-if="criteria.domains.length" class="space-y-0.5">
                <li v-for="domain in criteria.domains" :key="domain.name">{{ domain.name }} — {{ weight(domain.weight) }}</li>
            </ul>
            <p v-if="criteria.scale">Respondes na escala {{ criteriaState === 'previous' ? 'desta autoavaliação' : 'da turma' }}: {{ criteria.scale }}.</p>
        </section>

        <p v-if="hasEarlierRound" role="note" class="rounded-md border border-border bg-muted/30 px-3 py-2 text-sm" data-test="earlier-round-note">
            Já tinhas respondido a uma autoavaliação deste período com critérios anteriores. Essa resposta ficou guardada; esta é uma nova autoavaliação.
        </p>

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
            />

            <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-muted-foreground">A tua autoavaliação é comparada com a avaliação do professor, nunca entra no cálculo.</p>
                <button
                    type="submit"
                    class="min-h-11 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                    :disabled="form.processing || readOnly"
                >
                    Guardar autoavaliação
                </button>
            </div>
        </form>
    </div>
</template>
