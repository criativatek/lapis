<script setup lang="ts">
import { computed } from 'vue';
import type { SelfAssessmentQuestion } from '@/types/self-assessment';

const props = withDefaults(
    defineProps<{
        questions: SelfAssessmentQuestion[];
        /** What an older self-assessment wrote in the single box this form replaced. Shown, never edited. */
        earlierReflection: string | null;
        /** The Inertia form errors; keys are `answers.{id}` / `numbers.{id}`. */
        errors?: Record<string, string>;
        /** Who is reading the form — changes the person of the sentence under the global question. */
        audience?: 'student' | 'teacher';
    }>(),
    { errors: () => ({}), audience: 'student' },
);

const answers = defineModel<Record<number, number | null>>('answers', { required: true });
const texts = defineModel<Record<number, string>>('texts', { required: true });
const numbers = defineModel<Record<number, string>>('numbers', { default: () => ({}) });

const GLOBAL_SUPPORT = {
    student: 'É a tua apreciação pessoal da classificação que consideras merecer. Não é uma média das respostas anteriores.',
    teacher: 'É a apreciação pessoal do aluno — não é uma média das respostas por domínio nem altera a classificação atribuída.',
} as const;

/**
 * How a scale question is answered. The server decides (`answer.mode`); a
 * question that arrives without `answer` falls back to the levels it carries,
 * so an older payload still renders what it always did.
 */
function modeOf(question: SelfAssessmentQuestion): 'levels' | 'number' | 'unavailable' {
    if (question.answer) {
        return question.answer.mode;
    }

    return question.levels.length > 0 ? 'levels' : 'unavailable';
}

function levelsOf(question: SelfAssessmentQuestion) {
    return question.answer?.levels?.length ? question.answer.levels : question.levels;
}

// A question can fail under either key depending on how it is answered.
function errorOf(question: SelfAssessmentQuestion): string | null {
    return props.errors[`answers.${question.id}`] ?? props.errors[`numbers.${question.id}`] ?? null;
}

const hasQuestionErrors = computed(() => Object.keys(props.errors).some((key) => key.startsWith('answers.') || key.startsWith('numbers.')));

function fieldId(question: SelfAssessmentQuestion): string {
    return `self-assessment-q-${question.id}`;
}

/**
 * The three blocks the form is read in.
 *
 * `other` is not one of them: it collects any question identified neither by a
 * domain nor by a role, so that one authored by hand never vanishes from the
 * screen. It is empty in every template the app builds.
 */
const BLOCKS: { key: string; title: string }[] = [
    { key: 'performance', title: 'O meu desempenho' },
    { key: 'reflection', title: 'A minha reflexão' },
    { key: 'work', title: 'Sobre o trabalho realizado' },
    { key: 'other', title: 'Outras perguntas' },
];

// A block with no questions is not shown at all — a heading over an empty area
// says the form is unfinished when it is simply shorter.
const blocks = computed(() =>
    BLOCKS.map((block) => ({
        ...block,
        questions: props.questions.filter((question) => question.block === block.key),
    })).filter((block) => block.questions.length > 0),
);
</script>

<template>
    <div class="space-y-6">
        <!-- A refused answer is marked beside its own question; this says, at
             the top, that there is one to look for — on a long form, or on a
             phone, it may be well out of sight. -->
        <p v-if="hasQuestionErrors" role="alert" class="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive" data-test="question-errors-summary">
            Nada foi guardado: há respostas por corrigir, assinaladas junto de cada pergunta.
        </p>

        <section v-for="block in blocks" :key="block.key" class="space-y-3">
            <h2 class="border-b border-border pb-1 text-sm font-semibold">{{ block.title }}</h2>

            <div v-for="question in block.questions" :key="question.id" class="text-sm" :class="question.role === 'global' ? 'rounded-md border border-border bg-muted/40 p-3' : ''">
                <label :for="fieldId(question)" class="mb-1 block" :class="question.role === 'global' ? 'font-medium' : ''">{{ question.prompt }}</label>

                <p v-if="question.role === 'global'" class="mb-1 text-xs text-muted-foreground" data-test="global-support">{{ GLOBAL_SUPPORT[audience] }}</p>

                <!-- A scale with nothing to answer on — no levels, no limits, no
                     scale at all — is said, not shown as a control holding only
                     «—». Nothing is offered in its place: a level of some other
                     scale is not an answer on this one. -->
                <span
                    v-if="question.answer_kind === 'scale' && modeOf(question) === 'unavailable'"
                    :id="fieldId(question)"
                    role="note"
                    class="block rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
                    data-test="scale-without-levels"
                >
                    <!-- The teacher is told what is wrong with the scale; the
                         student only that the question cannot be answered yet. -->
                    <template v-if="question.answer">{{ audience === 'teacher' && question.answer.problem ? question.answer.problem : question.answer.instruction }}</template>
                    <template v-else>
                        Não há níveis para escolher<template v-if="question.scale">: a escala «{{ question.scale }}» não tem níveis</template>. Esta pergunta fica por responder.
                    </template>
                </span>

                <template v-else-if="question.answer_kind === 'scale' && modeOf(question) === 'levels'">
                    <p v-if="question.answer?.instruction" :id="`${fieldId(question)}-help`" class="mb-1 text-xs text-muted-foreground" data-test="answer-instruction">{{ question.answer.instruction }}</p>
                    <select
                        :id="fieldId(question)"
                        v-model="answers[question.id]"
                        class="min-h-11 w-full max-w-xs rounded-md border border-border bg-background px-2 py-1.5"
                        :aria-describedby="question.answer?.instruction ? `${fieldId(question)}-help` : undefined"
                        :aria-invalid="errorOf(question) ? 'true' : undefined"
                    >
                        <option :value="null">—</option>
                        <!-- The value being proposed is the number. The qualitative
                         mention rides along as support, never in its place. -->
                        <option v-for="level in levelsOf(question)" :key="level.id" :value="level.id">{{ level.code }} — {{ level.label }}</option>
                    </select>
                </template>

                <template v-else-if="question.answer_kind === 'scale'">
                    <p :id="`${fieldId(question)}-help`" class="mb-1 text-xs text-muted-foreground" data-test="answer-instruction">{{ question.answer?.instruction }}</p>
                    <div class="flex max-w-xs items-center gap-2">
                        <input
                            :id="fieldId(question)"
                            v-model="numbers[question.id]"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            class="min-h-11 w-full min-w-0 rounded-md border border-border bg-background px-3 py-2 tabular-nums"
                            :aria-describedby="`${fieldId(question)}-help`"
                            :aria-invalid="errorOf(question) ? 'true' : undefined"
                        />
                        <span v-if="question.answer?.unit" class="text-muted-foreground" aria-hidden="true">{{ question.answer.unit }}</span>
                    </div>
                </template>

                <textarea
                    v-else
                    :id="fieldId(question)"
                    v-model="texts[question.id]"
                    rows="3"
                    maxlength="5000"
                    class="min-h-11 w-full rounded-md border border-border bg-background px-3 py-2"
                    :aria-invalid="errorOf(question) ? 'true' : undefined"
                ></textarea>

                <p v-if="errorOf(question)" role="alert" class="mt-1 text-xs text-destructive" data-test="question-error">{{ errorOf(question) }}</p>
            </div>
        </section>

        <p
            v-if="!blocks.length"
            class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
        >
            Esta turma ainda não tem perguntas de autoavaliação.
        </p>

        <div v-if="earlierReflection" class="rounded-md border border-border bg-muted/30 p-3 text-sm">
            <p class="mb-1 text-xs font-medium text-muted-foreground">Reflexão escrita antes de o formulário ter estas perguntas</p>
            <p class="whitespace-pre-line">{{ earlierReflection }}</p>
        </div>
    </div>
</template>
