<script setup lang="ts">
import { computed } from 'vue';
import type { SelfAssessmentQuestion } from '@/types/self-assessment';

const props = defineProps<{
    questions: SelfAssessmentQuestion[];
    /** What an older self-assessment wrote in the single box this form replaced. Shown, never edited. */
    earlierReflection: string | null;
}>();

const answers = defineModel<Record<number, number | null>>('answers', { required: true });
const texts = defineModel<Record<number, string>>('texts', { required: true });

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
        <section v-for="block in blocks" :key="block.key" class="space-y-3">
            <h2 class="border-b border-border pb-1 text-sm font-semibold">{{ block.title }}</h2>

            <label
                v-for="question in block.questions"
                :key="question.id"
                class="block text-sm"
                :class="question.role === 'global' ? 'rounded-md border border-border bg-muted/40 p-3' : ''"
            >
                <span class="mb-1 block" :class="question.role === 'global' ? 'font-medium' : ''">{{ question.prompt }}</span>

                <select
                    v-if="question.answer_kind === 'scale'"
                    v-model="answers[question.id]"
                    class="w-full max-w-xs rounded-md border border-border bg-background px-2 py-1.5"
                >
                    <option :value="null">—</option>
                    <option v-for="level in question.levels" :key="level.id" :value="level.id">{{ level.code }} · {{ level.label }}</option>
                </select>

                <textarea
                    v-else
                    v-model="texts[question.id]"
                    rows="3"
                    maxlength="5000"
                    class="w-full rounded-md border border-border bg-background px-3 py-2"
                ></textarea>
            </label>
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
