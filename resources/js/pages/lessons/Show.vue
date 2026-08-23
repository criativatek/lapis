<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, BookOpenCheck, Check, RotateCcw, Save } from '@lucide/vue';
import { computed } from 'vue';
import AlertError from '@/components/AlertError.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Lesson = {
    ulid: string;
    starts_at: string;
    ends_at: string | null;
    status: 'preparation' | 'prepared' | 'taught';
    status_label: string;
    school_class: {
        ulid: string;
        label: string;
        subject: string;
    };
    plan: {
        planned_summary: string;
    } | null;
    summary: {
        content: string;
        reviewed_at: string | null;
    } | null;
};

const props = defineProps<{
    lesson: Lesson;
}>();

const summaryForm = useForm({
    content: props.lesson.summary?.content ?? '',
});
const planForm = useForm<{
    planned_summary: string;
    target_status: Lesson['status'];
}>({
    planned_summary: props.lesson.plan?.planned_summary ?? '',
    target_status: props.lesson.status,
});

const dateFormatter = new Intl.DateTimeFormat('pt-PT', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'Europe/Lisbon',
});
const timeFormatter = new Intl.DateTimeFormat('pt-PT', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Europe/Lisbon',
});

const lessonDate = computed(() => dateFormatter.format(new Date(props.lesson.starts_at)));
const lessonTime = computed(() => {
    const start = timeFormatter.format(new Date(props.lesson.starts_at));

    return props.lesson.ends_at
        ? `${start}–${timeFormatter.format(new Date(props.lesson.ends_at))}`
        : start;
});
const summaryErrors = computed(() => Object.values(summaryForm.errors));
const planErrors = computed(() => Object.values(planForm.errors));

function submitSummary(): void {
    summaryForm.put(`/lessons/${props.lesson.ulid}/summary`, {
        preserveScroll: true,
    });
}

function submitPlan(targetStatus: Lesson['status'] = props.lesson.status): void {
    planForm.target_status = targetStatus;
    planForm.put(`/lessons/${props.lesson.ulid}/plan`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`${lesson.school_class.label} — Sumário`" />

    <main class="mx-auto w-full max-w-3xl space-y-6 p-4 pb-28 sm:p-6 sm:pb-8">
        <Button as-child variant="ghost" class="-ml-3 min-h-11">
            <Link :href="`/classes/${lesson.school_class.ulid}`">
                <ArrowLeft class="size-4" />
                Voltar à turma
            </Link>
        </Button>

        <div class="space-y-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <Heading
                    :title="lesson.school_class.label"
                    :description="lesson.school_class.subject"
                />
                <Badge variant="secondary">{{ lesson.status_label }}</Badge>
            </div>

            <dl class="grid gap-3 rounded-xl border bg-card p-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Data</dt>
                    <dd class="mt-1 capitalize">{{ lessonDate }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Hora</dt>
                    <dd class="mt-1">{{ lessonTime }}</dd>
                </div>
            </dl>
        </div>

        <form class="space-y-4" @submit.prevent="submitSummary">
            <AlertError v-if="summaryErrors.length > 0" :errors="summaryErrors" title="Não foi possível guardar o sumário." />

            <div
                v-if="summaryForm.recentlySuccessful"
                role="status"
                class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200"
            >
                <Check class="size-4" />
                Sumário guardado.
            </div>

            <div class="grid gap-2">
                <Label for="lesson-summary" class="text-base font-semibold">Sumário</Label>
                <textarea
                    id="lesson-summary"
                    v-model="summaryForm.content"
                    name="content"
                    rows="10"
                    maxlength="16000"
                    required
                    autofocus
                    class="min-h-56 w-full resize-y rounded-xl border border-input bg-background px-4 py-3 text-base leading-relaxed shadow-xs outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
                    placeholder="Escreve o sumário desta aula…"
                    :disabled="summaryForm.processing"
                    aria-describedby="lesson-summary-error"
                />
                <InputError id="lesson-summary-error" :message="summaryForm.errors.content" />
            </div>

            <div>
                <div class="mx-auto flex max-w-3xl gap-3">
                    <Button type="submit" size="lg" class="min-h-12 flex-1 text-base" :disabled="summaryForm.processing">
                        <Spinner v-if="summaryForm.processing" />
                        <Save v-else class="size-5" />
                        {{ summaryForm.processing ? 'A guardar…' : 'Guardar sumário' }}
                    </Button>
                </div>
            </div>
        </form>

        <section class="space-y-4 rounded-xl border bg-card p-4 sm:p-6" aria-labelledby="lesson-plan-heading">
            <div class="space-y-1">
                <h2 id="lesson-plan-heading" class="text-base font-semibold">Planeamento</h2>
                <p class="text-sm text-muted-foreground">
                    Prepara a aula sem alterar o sumário oficial.
                </p>
            </div>

            <form class="space-y-4" @submit.prevent="submitPlan()">
                <AlertError v-if="planErrors.length > 0" :errors="planErrors" title="Não foi possível guardar o planeamento." />

                <div
                    v-if="planForm.recentlySuccessful"
                    role="status"
                    class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200"
                >
                    <Check class="size-4" />
                    Planeamento guardado.
                </div>

                <div class="grid gap-2">
                    <Label for="lesson-plan">Conteúdo planeado</Label>
                    <textarea
                        id="lesson-plan"
                        v-model="planForm.planned_summary"
                        name="planned_summary"
                        rows="6"
                        maxlength="16000"
                        class="min-h-36 w-full resize-y rounded-xl border border-input bg-background px-4 py-3 text-base leading-relaxed shadow-xs outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
                        placeholder="O que pretendes trabalhar nesta aula?"
                        :disabled="planForm.processing"
                        aria-describedby="lesson-plan-error"
                    />
                    <InputError id="lesson-plan-error" :message="planForm.errors.planned_summary" />
                    <InputError :message="planForm.errors.target_status" />
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button type="submit" variant="outline" :disabled="planForm.processing">
                        <Spinner v-if="planForm.processing" />
                        <Save v-else class="size-4" />
                        Guardar planeamento
                    </Button>

                    <Button
                        v-if="lesson.status === 'preparation'"
                        type="button"
                        :disabled="planForm.processing"
                        @click="submitPlan('prepared')"
                    >
                        <BookOpenCheck class="size-4" />
                        Marcar como preparada
                    </Button>

                    <Button
                        v-if="lesson.status === 'prepared'"
                        type="button"
                        variant="outline"
                        :disabled="planForm.processing"
                        @click="submitPlan('preparation')"
                    >
                        <RotateCcw class="size-4" />
                        Reabrir planeamento
                    </Button>

                    <Button
                        v-if="lesson.status !== 'taught'"
                        type="button"
                        variant="secondary"
                        :disabled="planForm.processing"
                        @click="submitPlan('taught')"
                    >
                        <Check class="size-4" />
                        Marcar como lecionada
                    </Button>
                </div>
            </form>
        </section>
    </main>
</template>
