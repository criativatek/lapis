<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, Copy, Save } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
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
        private_notes: string | null;
        resources: string | null;
        homework: string | null;
        reviewed_at: string | null;
    } | null;
};

const props = defineProps<{ lesson: Lesson }>();

const summaryForm = useForm({
    content: props.lesson.summary?.content ?? '',
    private_notes: props.lesson.summary?.private_notes ?? '',
    resources: props.lesson.summary?.resources ?? '',
    homework: props.lesson.summary?.homework ?? '',
});
const taughtForm = useForm({});

// "Basear no sumário anterior" — a read-only convenience (never a write) that
// offers the same class's most recent earlier sumário as an editable
// starting point. Fetched once, up front, only when there is nothing typed
// yet to lose — never overwrites anything the teacher already wrote.
type PreviousSummary = { content: string; private_notes: string | null; resources: string | null; homework: string | null };
const previousSummary = ref<PreviousSummary | null>(null);

onMounted(async () => {
    if (props.lesson.summary?.content) {
        return;
    }

    try {
        const response = await fetch(`/lessons/${props.lesson.ulid}/previous-summary`, {
            headers: { Accept: 'application/json' },
        });

        if (response.status !== 204) {
            previousSummary.value = (await response.json()) as PreviousSummary;
        }
    } catch {
        // Best-effort only — no previous summary is offered on failure.
    }
});

const canBasePrevious = computed(() => previousSummary.value !== null && summaryForm.content.trim() === '');

function basePreviousSummary(): void {
    if (previousSummary.value === null) {
        return;
    }

    summaryForm.content = previousSummary.value.content;
    summaryForm.private_notes = previousSummary.value.private_notes ?? '';
    summaryForm.resources = previousSummary.value.resources ?? '';
    summaryForm.homework = previousSummary.value.homework ?? '';
}

const dateFormatter = new Intl.DateTimeFormat('pt-PT', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Europe/Lisbon',
});
const timeFormatter = new Intl.DateTimeFormat('pt-PT', {
    hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Lisbon',
});

const lessonDate = computed(() => dateFormatter.format(new Date(props.lesson.starts_at)));
const lessonTime = computed(() => {
    const start = timeFormatter.format(new Date(props.lesson.starts_at));

    return props.lesson.ends_at ? `${start}–${timeFormatter.format(new Date(props.lesson.ends_at))}` : start;
});
const summaryErrors = computed(() => Object.values(summaryForm.errors));

// The week to return to is derived from the lesson itself, never threaded in
// from wherever the teacher happened to arrive from — a direct link, browser
// history and the weekly view all land on the same, correct week. Anchored at
// noon UTC on the Lisbon calendar date, the same way Index.vue builds its own
// `?week=` values, so a DST shift can never move the date by a day.
const originWeekHref = computed(() => {
    const lisbonDate = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Lisbon',
    }).format(new Date(props.lesson.starts_at));
    const date = new Date(`${lisbonDate}T12:00:00Z`);
    const isoWeekday = date.getUTCDay() === 0 ? 7 : date.getUTCDay();
    date.setUTCDate(date.getUTCDate() - (isoWeekday - 1));

    return `/lessons?week=${date.toISOString().slice(0, 10)}`;
});

function submitSummary(): void {
    summaryForm.put(`/lessons/${props.lesson.ulid}/summary`, { preserveScroll: true });
}

function markTaught(): void {
    taughtForm.post(`/lessons/${props.lesson.ulid}/mark-taught`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`${lesson.school_class.label} — Sumário`" />

    <main class="mx-auto w-full max-w-3xl space-y-6 p-4 pb-28 sm:p-6 sm:pb-8">
        <Button as-child variant="ghost" class="-ml-3 min-h-11">
            <Link :href="originWeekHref">
                <ArrowLeft class="size-4" />
                Voltar às aulas da semana
            </Link>
        </Button>

        <div class="space-y-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <Heading :title="lesson.school_class.label" :description="lesson.school_class.subject" />
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

            <div v-if="summaryForm.recentlySuccessful" role="status" class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
                <Check class="size-4" />
                Sumário guardado.
            </div>

            <div class="grid gap-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <Label for="lesson-summary" class="text-base font-semibold">Sumário</Label>
                    <Button v-if="canBasePrevious" type="button" variant="outline" size="sm" @click="basePreviousSummary">
                        <Copy class="size-4" /> Basear no sumário anterior
                    </Button>
                </div>
                <textarea id="lesson-summary" v-model="summaryForm.content" name="content" rows="10" maxlength="16000" required autofocus class="min-h-56 w-full resize-y rounded-xl border border-input bg-background px-4 py-3 text-base leading-relaxed shadow-xs outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50" placeholder="Escreve o sumário desta aula…" :disabled="summaryForm.processing" aria-describedby="lesson-summary-error" />
                <InputError id="lesson-summary-error" :message="summaryForm.errors.content" />
            </div>

            <details :open="Boolean(lesson.summary?.private_notes)" class="group rounded-xl border bg-card">
                <summary class="cursor-pointer px-4 py-3 font-semibold focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50 sm:px-5">Notas do professor</summary>
                <div class="grid gap-2 border-t px-4 py-4 sm:px-5">
                    <p id="private-notes-help" class="text-sm text-muted-foreground">Notas privadas, visíveis apenas para o professor e nunca mostradas aos alunos.</p>
                    <textarea id="lesson-private-notes" v-model="summaryForm.private_notes" name="private_notes" rows="5" maxlength="16000" class="min-h-32 w-full resize-y rounded-xl border border-input bg-background px-4 py-3 leading-relaxed" :disabled="summaryForm.processing" aria-describedby="private-notes-help private-notes-error" />
                    <InputError id="private-notes-error" :message="summaryForm.errors.private_notes" />
                </div>
            </details>

            <details :open="Boolean(lesson.summary?.resources)" class="group rounded-xl border bg-card">
                <summary class="cursor-pointer px-4 py-3 font-semibold focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50 sm:px-5">Recursos</summary>
                <div class="grid gap-2 border-t px-4 py-4 sm:px-5">
                    <Label for="lesson-resources" class="sr-only">Recursos</Label>
                    <textarea id="lesson-resources" v-model="summaryForm.resources" name="resources" rows="4" maxlength="16000" class="min-h-28 w-full resize-y rounded-xl border border-input bg-background px-4 py-3 leading-relaxed" placeholder="Referência ou URL" :disabled="summaryForm.processing" aria-describedby="resources-error" />
                    <InputError id="resources-error" :message="summaryForm.errors.resources" />
                </div>
            </details>

            <details :open="Boolean(lesson.summary?.homework)" class="group rounded-xl border bg-card">
                <summary class="cursor-pointer px-4 py-3 font-semibold focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50 sm:px-5">TPC</summary>
                <div class="grid gap-2 border-t px-4 py-4 sm:px-5">
                    <Label for="lesson-homework" class="sr-only">TPC</Label>
                    <textarea id="lesson-homework" v-model="summaryForm.homework" name="homework" rows="4" maxlength="16000" class="min-h-28 w-full resize-y rounded-xl border border-input bg-background px-4 py-3 leading-relaxed" :disabled="summaryForm.processing" aria-describedby="homework-error" />
                    <InputError id="homework-error" :message="summaryForm.errors.homework" />
                </div>
            </details>

            <div class="flex flex-col gap-3 sm:flex-row">
                <Button type="submit" size="lg" class="min-h-12 flex-1 text-base" :disabled="summaryForm.processing">
                    <Spinner v-if="summaryForm.processing" />
                    <Save v-else class="size-5" />
                    {{ summaryForm.processing ? 'A guardar…' : 'Guardar' }}
                </Button>
                <Button v-if="lesson.status !== 'taught'" type="button" size="lg" variant="secondary" class="min-h-12" :disabled="taughtForm.processing" @click="markTaught">
                    <Spinner v-if="taughtForm.processing" />
                    <Check v-else class="size-5" />
                    Marcar como lecionada
                </Button>
            </div>
        </form>
    </main>
</template>
