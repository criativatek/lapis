<script setup lang="ts">
import { CalendarX } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import CalendarRegularizeDialog from '@/components/calendar-conflicts/CalendarRegularizeDialog.vue';
import type { ReviewLesson } from '@/components/calendar-conflicts/CalendarRegularizeDialog.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { plural, requestJson } from '@/lib/calendarReconciliation';

type Conflicts = {
    can_regularize: boolean;
    regularize_blocked?: 'year_closed' | 'read_only' | 'support_session' | null;
    counts: { A: number; B: number; C: number };
    /** As interrupções de TODAS as aulas contadas (A, B e C). */
    exception_titles?: string[];
    review_lessons: ReviewLesson[];
};

/**
 * O AVISO DO PROFESSOR em «Aulas e Sumários»: há aulas das suas turmas em
 * dias sem aulas (feriados, interrupções, dias não letivos criados depois de as
 * aulas existirem).
 *
 * LEVE E DISCRETO. Pede-se depois de a página montar, a um endereço próprio:
 * não pesa no carregamento e, se falhar, não diz nada — a página funciona
 * exatamente como antes. Sem conflitos, não desenha nada. «Ver e corrigir» abre
 * a pré-visualização; depois de corrigir, o aviso pede os dados outra vez e a
 * página que o contém recarrega as aulas (`changed`).
 *
 * QUANDO JÁ SÓ HÁ AULAS PARA REVER (nada vazio, nenhum planeamento que desça
 * sozinho), o aviso deixa de prometer correção: diz que essas aulas precisam
 * da revisão do professor e o botão só as mostra.
 */
const emit = defineEmits<{ changed: [] }>();

const conflicts = ref<Conflicts | null>(null);
const dialogOpen = ref(false);
const feedback = ref<string | null>(null);

async function load(): Promise<void> {
    try {
        const result = await requestJson<Conflicts>('/lessons/calendar-conflicts', 'GET');

        // Uma falha ao voltar a pedir (ao abrir o diálogo) não apaga o que já
        // se sabia; a primeira falha deixa o aviso por desenhar.
        if (result.ok) {
            conflicts.value = result.body;
        }
    } catch {
        // Falha silenciosa: o aviso é uma ajuda e nunca uma condição da página.
    }
}

onMounted(() => {
    void load();
});

const total = computed(() => {
    const counts = conflicts.value?.counts;

    return counts ? counts.A + counts.B + counts.C : 0;
});

const reviewLessons = computed(() => conflicts.value?.review_lessons ?? []);
const hasConflicts = computed(() => total.value > 0 || reviewLessons.value.length > 0);
/** Quantas aulas o aviso conta (para a concordância das frases). */
const reviewCount = computed(() => Math.max(total.value, reviewLessons.value.length));

/** Nada se corrige automaticamente: só restam aulas para rever à mão. */
const onlyReview = computed(() => {
    const counts = conflicts.value?.counts;

    return counts !== undefined && counts.A + counts.B === 0 && hasConflicts.value;
});
const canRegularize = computed(() => conflicts.value?.can_regularize ?? false);
const blockedReason = computed(() => conflicts.value?.regularize_blocked ?? null);

/**
 * O modo do diálogo, decidido ao abrir: só para rever, ou para corrigir. Fica
 * fixo enquanto está aberto — o aviso volta a ser pedido ao abrir, e mudar de
 * modo a meio tirava o botão de confirmar debaixo do dedo.
 */
const dialogReviewOnly = ref(false);

/** Abrir o diálogo volta a pedir o aviso: o que estava na página pode já não ser o de agora. */
function openDialog(): void {
    dialogReviewOnly.value = onlyReview.value;
    dialogOpen.value = true;
    void load();
}

const titles = computed(() => {
    const unique = new Set<string>(conflicts.value?.exception_titles ?? []);

    for (const lesson of reviewLessons.value) {
        for (const title of lesson.exception_titles) {
            unique.add(title);
        }
    }

    return [...unique];
});

const headline = computed(() => {
    const count = Math.max(total.value, reviewLessons.value.length);
    const base = `Há ${plural(count, 'aula das tuas turmas', 'aulas das tuas turmas')} em dias sem aulas`;
    const named = titles.value.length > 0 ? `${base} (${titles.value.join(', ')})` : base;

    return onlyReview.value ? `${named} que ${count === 1 ? 'precisa' : 'precisam'} da tua revisão` : named;
});

async function onDone(message: string): Promise<void> {
    feedback.value = message;
    await load();
    emit('changed');
}
</script>

<template>
    <div v-if="hasConflicts || feedback || dialogOpen" class="mt-4 space-y-2" data-testid="calendar-conflicts">
        <Alert v-if="hasConflicts" data-testid="calendar-conflicts-alert">
            <CalendarX aria-hidden="true" />
            <AlertTitle>{{ headline }}</AlertTitle>
            <AlertDescription>
                <p data-testid="calendar-conflicts-text">
                    <template v-if="canRegularize && onlyReview">
                        Já foi corrigido o que podia ser corrigido automaticamente.
                        {{ reviewCount === 1 ? 'Esta fica como está: abre-a e decide.' : 'Estas ficam como estão: abre cada uma e decide.' }}
                    </template>
                    <template v-else-if="canRegularize">
                        Podem ser corrigidas daqui: as aulas vazias saem e o planeamento passa para a aula seguinte.
                        As que não podem ser corrigidas automaticamente ficam como estão, para as reveres.
                    </template>
                    <template v-else>Ficam como estão: aqui só podes consultá-las.</template>
                </p>
                <!-- Alvo táctil de 44 px em qualquer largura (tablets incluídos). -->
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="mt-2 min-h-11"
                    data-testid="calendar-conflicts-open"
                    @click="openDialog"
                >
                    {{ !canRegularize ? 'Ver aulas' : onlyReview ? 'Ver aulas para rever' : 'Ver e corrigir' }}
                </Button>
            </AlertDescription>
        </Alert>

        <p
            v-if="feedback"
            role="status"
            class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200"
            data-testid="calendar-conflicts-feedback"
        >
            {{ feedback }}
        </p>

        <CalendarRegularizeDialog
            v-model:open="dialogOpen"
            :can-regularize="canRegularize"
            :blocked-reason="blockedReason"
            :review-lessons="reviewLessons"
            :review-only="dialogReviewOnly"
            @done="onDone"
        />
    </div>
</template>
