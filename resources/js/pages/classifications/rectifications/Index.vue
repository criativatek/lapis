<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FilePenLine } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';

/**
 * «Retificações»: a porta do responsável da instituição para as classificações
 * já publicadas de turmas que não leciona, em períodos encerrados.
 *
 * Só desenha o que o servidor manda (ClassificationRectificationController):
 * turmas com, pelo menos, um período encerrado com classificações publicadas.
 * Nada aqui é média, proposta ou autoavaliação.
 */
type PeriodEntry = {
    ulid: string;
    label: string;
    published_count: number;
    rectified_count: number;
};

type ClassEntry = {
    ulid: string;
    label: string;
    subject: string | null;
    academic_year: string;
    teachers: string[];
    periods: PeriodEntry[];
};

/** Todas as turmas da instituição (0.194.0): a entrada para «Aulas fechadas». */
type LessonClassEntry = {
    ulid: string;
    label: string;
    subject: string | null;
    academic_year: string;
};

withDefaults(defineProps<{ classes: ClassEntry[]; lessonClasses?: LessonClassEntry[] }>(), { lessonClasses: () => [] });

function counts(period: PeriodEntry): string {
    const published = period.published_count === 1 ? '1 publicada' : `${period.published_count} publicadas`;
    const rectified = period.rectified_count === 1 ? '1 retificada' : `${period.rectified_count} retificadas`;

    return `${published} · ${rectified}`;
}
</script>

<template>
    <Head title="Retificações" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            title="Retificações"
            description="Aqui pode retificar classificações já publicadas de períodos encerrados, incluindo de turmas que não leciona. Cada consulta fica registada."
        />

        <EmptyState
            v-if="classes.length === 0"
            :icon="FilePenLine"
            title="Não há classificações publicadas em períodos encerrados."
        />

        <ul v-else class="space-y-4" data-testid="rectification-classes">
            <li
                v-for="schoolClass in classes"
                :key="schoolClass.ulid"
                class="rounded-xl border border-border"
                data-testid="rectification-class"
            >
                <div class="space-y-0.5 px-4 py-3">
                    <h3 class="text-base font-semibold">
                        {{ schoolClass.label }}
                        <span v-if="schoolClass.subject" class="font-normal text-muted-foreground">— {{ schoolClass.subject }}</span>
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        {{ schoolClass.academic_year }}
                        <template v-if="schoolClass.teachers.length > 0"> · {{ schoolClass.teachers.join(', ') }}</template>
                        <template v-else> · Sem professor atribuído</template>
                    </p>
                </div>
                <ul class="divide-y divide-border border-t border-border">
                    <li v-for="period in schoolClass.periods" :key="period.ulid">
                        <Link
                            :href="`/classes/${schoolClass.ulid}/retificacoes/${period.ulid}`"
                            class="flex min-h-11 flex-wrap items-center justify-between gap-x-3 gap-y-0.5 px-4 py-2 text-sm hover:bg-muted/40 md:min-h-9"
                            data-testid="rectification-period-link"
                        >
                            <span class="font-medium">{{ period.label }}</span>
                            <span class="text-muted-foreground">{{ counts(period) }}</span>
                        </Link>
                    </li>
                </ul>
            </li>
        </ul>

        <!-- Aulas fechadas (0.194.0): anular um «Lecionada» de qualquer turma da
             instituição. Sem sumário nem faltas — ver LessonAnnulmentController. -->
        <section v-if="lessonClasses.length > 0" class="space-y-2" data-testid="annulment-classes">
            <h2 class="text-base font-semibold">Aulas fechadas</h2>
            <p class="text-sm text-muted-foreground">
                Anular uma aula marcada como lecionada por engano (a aula não se realizou). Sem acesso ao sumário nem às
                faltas.
            </p>
            <ul class="divide-y divide-border rounded-xl border border-border">
                <li v-for="schoolClass in lessonClasses" :key="schoolClass.ulid">
                    <Link
                        :href="`/classes/${schoolClass.ulid}/retificacoes/aulas`"
                        class="flex min-h-11 items-center justify-between gap-3 px-4 py-2 text-sm hover:bg-muted/40"
                        data-testid="annulment-class-link"
                    >
                        <span class="font-medium">
                            {{ schoolClass.label }}<span v-if="schoolClass.subject" class="font-normal text-muted-foreground"> — {{ schoolClass.subject }}</span>
                        </span>
                        <span class="text-muted-foreground">{{ schoolClass.academic_year }}</span>
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
