<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Undo2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import LessonOutcomeDialog from '@/components/lessons/LessonOutcomeDialog.vue';
import { Button } from '@/components/ui/button';
import type { AnnulTaught, NotHeldOptions } from '@/lib/lessonOutcomeOptions';

/**
 * «Aulas fechadas» de uma turma, para a direção da instituição (0.194.0): a
 * porta por onde anula um «Lecionada» de uma turma que não leciona.
 *
 * CORRIGIR ≠ CONSULTAR. Esta página mostra só o que a semana já mostra a
 * qualquer colega — data, hora, turma ou grupo, resultado, número, quem fechou.
 * Nunca o sumário, as faltas, a causa detalhada nem nenhum dado reservado
 * (LessonAnnulmentController). O diálogo é o mesmo da página da aula; o
 * destino do sumário não se conhece aqui, e o aviso diz as duas possibilidades.
 */

type ClosedLesson = {
    ulid: string;
    date: string;
    time: string;
    context_label: string;
    outcome: string;
    outcome_label: string;
    lesson_number: number | null;
    closed_by: string | null;
    can_annul: boolean;
};

type Annulment = {
    ulid: string;
    at: string;
    by: string | null;
    lesson_date: string | null;
    lesson_number: number | null;
    motive: string;
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string | null; academic_year: string };
    lessons: ClosedLesson[];
    annulments: Annulment[];
    notHeldOptions: NotHeldOptions | null;
}>();

const open = ref(false);
const chosen = ref<ClosedLesson | null>(null);

/** A direção não sabe se há aulas fechadas a seguir: o aviso diz as duas possibilidades. */
const annul = computed<AnnulTaught | null>(() =>
    chosen.value === null ? null : { summary_destination: 'history', lesson_number: chosen.value.lesson_number, closed_after: null },
);

function startAnnulment(lesson: ClosedLesson): void {
    chosen.value = lesson;
    open.value = true;
}

const when = (iso: string): string => new Date(iso).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' });

const title = computed(() => `Aulas fechadas — ${props.schoolClass.label}`);
</script>

<template>
    <Head :title="title" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <Heading
            :title="title"
            :description="`${schoolClass.subject ? `${schoolClass.subject} · ` : ''}${schoolClass.academic_year}. Anular uma aula marcada como lecionada por engano: a aula não se realizou. Sem acesso ao sumário nem às faltas.`"
        />
        <Link href="/retificacoes" class="text-sm text-muted-foreground hover:underline">← Retificações</Link>

        <p v-if="!notHeldOptions" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" data-testid="annulment-feature-off">
            A funcionalidade «Aula não realizada» está desligada nesta instalação: não é possível anular aulas lecionadas.
        </p>

        <EmptyState v-if="lessons.length === 0" title="Esta turma ainda não tem aulas fechadas." :icon="Undo2" />

        <div v-else class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full text-sm" data-testid="closed-lessons">
                <thead class="bg-muted text-left text-xs">
                    <tr>
                        <th class="px-3 py-2 font-medium">Data</th>
                        <th class="px-3 py-2 font-medium">Hora</th>
                        <th class="px-3 py-2 font-medium">Turma</th>
                        <th class="px-3 py-2 font-medium">Resultado</th>
                        <th class="px-3 py-2 text-center font-medium">N.º</th>
                        <th class="px-3 py-2 font-medium">Fechada por</th>
                        <th class="px-3 py-2"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="lesson in lessons" :key="lesson.ulid" class="border-t border-border" data-testid="closed-lesson">
                        <td class="px-3 py-2 whitespace-nowrap">{{ lesson.date }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ lesson.time }}</td>
                        <td class="px-3 py-2">{{ lesson.context_label }}</td>
                        <td class="px-3 py-2">{{ lesson.outcome_label }}</td>
                        <td class="px-3 py-2 text-center tabular-nums">{{ lesson.lesson_number ?? '—' }}</td>
                        <td class="px-3 py-2">{{ lesson.closed_by ?? '—' }}</td>
                        <td class="px-3 py-2 text-right">
                            <Button
                                v-if="lesson.can_annul && notHeldOptions"
                                type="button"
                                variant="outline"
                                size="sm"
                                data-testid="annul-lesson"
                                @click="startAnnulment(lesson)"
                            >
                                <Undo2 class="size-4" /> Anular lecionada
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <LessonOutcomeDialog
            v-if="notHeldOptions"
            v-model:open="open"
            :lesson-ulid="chosen?.ulid ?? null"
            :reasons="[]"
            :not-held-options="notHeldOptions"
            :annul="annul"
            :lesson-context="chosen ? `${chosen.context_label} · ${chosen.date} ${chosen.time}` : null"
        />

        <section class="space-y-2" data-testid="annulments">
            <h2 class="text-base font-semibold">Anulações ({{ annulments.length }})</h2>
            <p v-if="annulments.length === 0" class="text-sm text-muted-foreground">Nenhuma aula desta turma foi anulada.</p>
            <ul v-else class="divide-y divide-border rounded-xl border border-border">
                <li v-for="entry in annulments" :key="entry.ulid" class="space-y-0.5 px-4 py-2 text-sm" data-testid="annulment-entry">
                    <p class="font-medium">
                        {{ when(entry.at) }}<span v-if="entry.by"> · {{ entry.by }}</span>
                        <span v-if="entry.lesson_date"> · aula de {{ entry.lesson_date }}</span>
                        <span v-if="entry.lesson_number !== null"> · tinha o n.º {{ entry.lesson_number }}</span>
                    </p>
                    <p class="text-muted-foreground">Motivo: {{ entry.motive }}</p>
                </li>
            </ul>
        </section>
    </div>
</template>
