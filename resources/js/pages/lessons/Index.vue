<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    BookOpen,
    CalendarDays,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    FileText,
    Plus,
} from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Lesson = {
    ulid: string;
    starts_at: string;
    ends_at: string | null;
    school_class: { ulid: string; label: string };
    subject: string;
    status: 'preparation' | 'prepared' | 'taught';
    status_label: string;
    has_summary: boolean;
    summary_excerpt: string | null;
};

const props = defineProps<{
    lessons: Lesson[];
    week: { start: string; end: string };
    academicYear: string | null;
    configuredClassesCount: number;
}>();

const dateFormatter = new Intl.DateTimeFormat('pt-PT', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: 'Europe/Lisbon',
});
const shortDateFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
});
const timeFormatter = new Intl.DateTimeFormat('pt-PT', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Europe/Lisbon',
});
const materializeForm = useForm({
    from: props.week.start,
    to: props.week.end,
});
const materializeError = computed(
    () => Object.values(materializeForm.errors)[0],
);

const days = computed(() => {
    const grouped = new Map<string, Lesson[]>();

    for (const lesson of props.lessons) {
        const key = lesson.starts_at.slice(0, 10);
        grouped.set(key, [...(grouped.get(key) ?? []), lesson]);
    }

    return [...grouped.entries()].map(([date, lessons]) => ({ date, lessons }));
});

const weekLabel = computed(
    () =>
        `${shortDateFormatter.format(new Date(`${props.week.start}T00:00:00Z`))} – ${shortDateFormatter.format(new Date(`${props.week.end}T00:00:00Z`))}`,
);

function navigate(offsetDays: number): void {
    const date = new Date(`${props.week.start}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + offsetDays);
    router.get(
        '/lessons',
        { week: date.toISOString().slice(0, 10) },
        { preserveState: true, preserveScroll: true },
    );
}

function goToday(): void {
    router.get(
        '/lessons',
        {
            week: new Intl.DateTimeFormat('en-CA', {
                timeZone: 'Europe/Lisbon',
            }).format(new Date()),
        },
        { preserveState: true },
    );
}

function materialize(): void {
    materializeForm.from = props.week.start;
    materializeForm.to = props.week.end;
    materializeForm.post('/lessons/materialize-week', { preserveScroll: true });
}

function lessonTime(lesson: Lesson): string {
    const start = timeFormatter.format(new Date(lesson.starts_at));

    return lesson.ends_at
        ? `${start}–${timeFormatter.format(new Date(lesson.ends_at))}`
        : start;
}

function badgeVariant(
    status: Lesson['status'],
): 'secondary' | 'default' | 'outline' {
    if (status === 'taught') {
        return 'default';
    }

    return status === 'prepared' ? 'secondary' : 'outline';
}
</script>

<template>
    <Head title="Aulas e Sumários" />
    <main class="mx-auto w-full max-w-4xl space-y-6 p-4 pb-24 sm:p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="Aulas e Sumários"
                :description="
                    academicYear
                        ? `Semana de ${weekLabel} · ${academicYear}`
                        : `Semana de ${weekLabel}`
                "
            />
            <Button
                class="min-h-11 shrink-0"
                :disabled="materializeForm.processing || !academicYear"
                @click="materialize"
            >
                <Plus class="size-4" />{{
                    materializeForm.processing
                        ? 'A preparar…'
                        : 'Preparar aulas desta semana'
                }}
            </Button>
        </div>
        <InputError :message="materializeError" />

        <nav
            class="flex flex-wrap items-center gap-2"
            aria-label="Navegação entre semanas"
        >
            <Button
                variant="outline"
                size="sm"
                class="min-h-10"
                @click="navigate(-7)"
                ><ChevronLeft class="size-4" /> Semana anterior</Button
            >
            <Button
                variant="outline"
                size="sm"
                class="min-h-10"
                @click="goToday"
                ><CalendarDays class="size-4" /> Semana atual</Button
            >
            <Button
                variant="outline"
                size="sm"
                class="min-h-10"
                @click="navigate(7)"
                >Semana seguinte <ChevronRight class="size-4"
            /></Button>
        </nav>

        <div
            v-if="!academicYear"
            class="rounded-xl border border-dashed p-6 text-center"
        >
            <CalendarDays class="mx-auto size-8 text-muted-foreground" />
            <h2 class="mt-3 font-semibold">Seleciona um ano letivo</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                A semana usa o ano letivo selecionado no topo da aplicação.
            </p>
        </div>

        <div
            v-else-if="days.length === 0"
            class="rounded-xl border border-dashed p-6 text-center"
        >
            <BookOpen class="mx-auto size-8 text-muted-foreground" />
            <h2 class="mt-3 font-semibold">Sem aulas nesta semana</h2>
            <p class="mx-auto mt-1 max-w-lg text-sm text-muted-foreground">
                {{
                    configuredClassesCount > 0
                        ? 'Usa “Preparar aulas desta semana” para criar as ocorrências do horário.'
                        : 'Configura primeiro o horário na página de cada turma.'
                }}
            </p>
        </div>

        <div v-else class="space-y-6">
            <section v-for="day in days" :key="day.date" class="space-y-2">
                <h2 class="text-sm font-semibold capitalize">
                    {{
                        dateFormatter.format(
                            new Date(`${day.date}T12:00:00+01:00`),
                        )
                    }}
                </h2>
                <ul class="divide-y rounded-xl border bg-card">
                    <li v-for="lesson in day.lessons" :key="lesson.ulid">
                        <Link
                            :href="`/lessons/${lesson.ulid}`"
                            class="flex min-h-20 items-start gap-3 p-4 transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring sm:items-center"
                        >
                            <time
                                class="w-24 shrink-0 text-sm font-medium tabular-nums"
                                >{{ lessonTime(lesson) }}</time
                            >
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{
                                        lesson.school_class.label
                                    }}</span>
                                    <span
                                        class="text-sm text-muted-foreground"
                                        >{{ lesson.subject }}</span
                                    >
                                </div>
                                <p
                                    v-if="lesson.summary_excerpt"
                                    class="mt-1 truncate text-sm text-muted-foreground"
                                >
                                    {{ lesson.summary_excerpt }}
                                </p>
                                <p
                                    v-else
                                    class="mt-1 flex items-center gap-1 text-xs text-muted-foreground"
                                >
                                    <FileText class="size-3.5" /> Sem sumário
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-2">
                                <Badge :variant="badgeVariant(lesson.status)">{{
                                    lesson.status_label
                                }}</Badge>
                                <span
                                    v-if="lesson.has_summary"
                                    class="flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400"
                                    ><CheckCircle2 class="size-3.5" />
                                    Sumário</span
                                >
                            </div>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </main>
</template>
