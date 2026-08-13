<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AuthSimpleLayout from '@/layouts/auth/AuthSimpleLayout.vue';

defineOptions({ layout: AuthSimpleLayout });

type Level = { id: number; code: string; label: string };
type Question = { id: number; prompt: string; answer_level_id: number | null; calculated: string | null };

const props = defineProps<{
    schoolClass: { ulid: string; label: string };
    period: { ulid: string; label: string };
    student: string;
    levels: Level[];
    questions: Question[];
    reflection: string | null;
    status: string | null;
    submitUrl: string;
}>();

// answers: question id → chosen scale level id (or null).
const initialAnswers: Record<number, number | null> = {};

for (const question of props.questions) {
    initialAnswers[question.id] = question.answer_level_id;
}

const form = useForm<{ reflection: string; answers: Record<number, number | null> }>({
    reflection: props.reflection ?? '',
    answers: initialAnswers,
});

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

        <form class="space-y-4" @submit.prevent="submit">
            <div v-if="questions.length" class="overflow-hidden rounded-lg border border-border">
                <div class="flex items-center justify-between border-b border-border bg-muted/40 px-4 py-2 text-xs font-medium text-muted-foreground">
                    <span>Domínio — como te avalias</span>
                    <span>Calculado</span>
                </div>
                <div v-for="question in questions" :key="question.id" class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3 last:border-0">
                    <label class="min-w-0 flex-1 text-sm">
                        <span class="mb-1 block">{{ question.prompt }}</span>
                        <select v-model="form.answers[question.id]" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                            <option :value="null">—</option>
                            <option v-for="level in levels" :key="level.id" :value="level.id">{{ level.code }} · {{ level.label }}</option>
                        </select>
                    </label>
                    <span class="shrink-0 text-sm tabular-nums text-muted-foreground" title="Resultado calculado neste domínio">
                        {{ question.calculated === null ? '—' : `${Number(question.calculated).toFixed(0)}%` }}
                    </span>
                </div>
            </div>

            <p v-else class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                Esta turma não tem perfil com domínios, por isso a autoavaliação é só reflexão.
            </p>

            <label class="block text-sm">
                <span class="mb-1 block font-medium">Reflexão</span>
                <textarea
                    v-model="form.reflection"
                    rows="4"
                    maxlength="5000"
                    class="w-full rounded-md border border-border bg-background px-3 py-2"
                    placeholder="O que correu bem, o que quero melhorar…"
                ></textarea>
            </label>

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
