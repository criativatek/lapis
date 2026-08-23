<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, Save } from '@lucide/vue';
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
    summary: {
        content: string;
        reviewed_at: string | null;
    } | null;
};

const props = defineProps<{
    lesson: Lesson;
}>();

const form = useForm({
    content: props.lesson.summary?.content ?? '',
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
const errors = computed(() => Object.values(form.errors));

function submit(): void {
    form.put(`/lessons/${props.lesson.ulid}/summary`, {
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

        <form class="space-y-4" @submit.prevent="submit">
            <AlertError v-if="errors.length > 0" :errors="errors" title="Não foi possível guardar o sumário." />

            <div
                v-if="form.recentlySuccessful"
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
                    v-model="form.content"
                    name="content"
                    rows="10"
                    maxlength="16000"
                    required
                    autofocus
                    class="min-h-56 w-full resize-y rounded-xl border border-input bg-background px-4 py-3 text-base leading-relaxed shadow-xs outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
                    placeholder="Escreve o sumário desta aula…"
                    :disabled="form.processing"
                    aria-describedby="lesson-summary-error"
                />
                <InputError id="lesson-summary-error" :message="form.errors.content" />
            </div>

            <div class="fixed inset-x-0 bottom-0 z-20 border-t bg-background/95 p-4 backdrop-blur sm:static sm:border-0 sm:bg-transparent sm:p-0">
                <div class="mx-auto flex max-w-3xl gap-3">
                    <Button type="submit" size="lg" class="min-h-12 flex-1 text-base" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        <Save v-else class="size-5" />
                        {{ form.processing ? 'A guardar…' : 'Guardar sumário' }}
                    </Button>
                </div>
            </div>
        </form>
    </main>
</template>
