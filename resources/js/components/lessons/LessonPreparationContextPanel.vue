<script setup lang="ts">
/**
 * «AULA ANTERIOR» — a aula (lecionada ou preparada por lecionar) imediatamente
 * antes da que se está a preparar, no mesmo público. Só leitura.
 *
 * UM SÓ CARTÃO, com o conteúdo COMPLETO (sumário e/ou plano, TPC e recursos),
 * sem encurtar. Sem aula anterior elegível o bloco não aparece — nem título,
 * nem «sem aulas». O estado diz sempre se a aula foi LECIONADA ou só está
 * PREPARADA — um plano não é matéria dada. A cor é reforço: o selo leva sempre
 * o texto. A versão `compact` (editor no cartão) só difere no nível do título e
 * em vir recolhida.
 *
 * QUEBRA DE TEXTO: o cartão e tudo o que lá está têm `min-w-0` e
 * `wrap-anywhere`, para que um URL longo (recursos, sumário) nunca passe a
 * largura do painel, em computador ou em telemóvel.
 */
import { Link } from '@inertiajs/vue3';
import { ChevronDown, History, RotateCw } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useLessonPreparationContext } from '@/composables/useLessonPreparationContext';
import { contextHistoryUrl } from '@/lib/lessonContext';
import { lessonNumberLabel } from '@/lib/lessons';
import { statusToneClasses } from '@/lib/statusTone';
import { capitalizeFirst } from '@/lib/text';

const props = withDefaults(
    defineProps<{
        lessonUlid: string;
        compact?: boolean;
        defaultOpen?: boolean;
    }>(),
    { compact: false, defaultOpen: true },
);

const ulid = computed(() => props.lessonUlid);
const { entry, loading, error, load } = useLessonPreparationContext(ulid);

const open = ref(props.defaultOpen);
const panelId = computed(() => `lesson-context-${props.lessonUlid}`);

onMounted(() => void load());

const weekdayDate = new Intl.DateTimeFormat('pt-PT', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: 'Europe/Lisbon',
});
const clock = new Intl.DateTimeFormat('pt-PT', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Europe/Lisbon',
});

const heading = computed(() => {
    if (entry.value === null) {
        return '';
    }

    const when = new Date(entry.value.starts_at);

    return `${capitalizeFirst(weekdayDate.format(when))}, ${clock.format(when)}`;
});

const hasText = computed(() => entry.value !== null && (entry.value.content.trim() !== '' || (entry.value.plan ?? '') !== ''));
const visible = computed(() => entry.value !== null || (error.value && !loading.value));

const textClasses = 'min-w-0 text-sm leading-relaxed whitespace-pre-line wrap-anywhere';
</script>

<template>
    <section v-if="visible" class="min-w-0 rounded-xl border bg-card" :data-testid="compact ? 'lesson-context-compact' : 'lesson-context'">
        <!-- h3 dentro do editor do cartão: lá o h2 é o dia da semana. -->
        <component :is="compact ? 'h3' : 'h2'" class="text-sm font-semibold">
            <button
                type="button"
                class="flex min-h-11 w-full items-center gap-2 rounded-xl px-4 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                :aria-expanded="open"
                :aria-controls="panelId"
                data-testid="lesson-context-toggle"
                @click="open = !open"
            >
                <History class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                Aula anterior
                <ChevronDown :class="['ml-auto size-4 shrink-0 transition-transform', open ? 'rotate-180' : '']" aria-hidden="true" />
            </button>
        </component>

        <div v-show="open" :id="panelId" class="grid min-w-0 gap-3 border-t px-4 py-3">
            <div v-if="error && entry === null" role="alert" class="flex flex-wrap items-center gap-2 text-sm" data-testid="lesson-context-error">
                <span class="text-destructive">Não foi possível carregar a aula anterior.</span>
                <Button type="button" variant="outline" size="sm" class="min-h-11 sm:min-h-8" @click="load">
                    <RotateCw class="size-4" aria-hidden="true" /> Tentar de novo
                </Button>
            </div>

            <template v-else-if="entry !== null">
                <article class="grid min-w-0 gap-2 rounded-lg border bg-background p-3" data-testid="lesson-context-entry">
                    <p class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-muted-foreground">
                        <strong class="font-semibold text-foreground" data-testid="lesson-context-when">{{ heading }}</strong>
                        <span v-if="entry.lesson_number !== null" class="tabular-nums" data-testid="lesson-context-number">{{
                            lessonNumberLabel(entry.lesson_number, entry.lesson_number_end)
                        }}</span>
                        <Badge variant="secondary" data-testid="lesson-context-state" :class="statusToneClasses(entry.state)">{{
                            entry.state_label
                        }}</Badge>
                    </p>

                    <p v-if="!hasText" class="text-sm text-muted-foreground" data-testid="lesson-context-no-summary">Sem sumário registado.</p>

                    <div v-if="entry.content.trim() !== ''" class="grid min-w-0 gap-0.5" data-testid="lesson-context-content">
                        <p class="text-xs font-semibold text-muted-foreground">Sumário</p>
                        <p :class="textClasses">{{ entry.content }}</p>
                    </div>

                    <div v-if="entry.plan" class="grid min-w-0 gap-0.5" data-testid="lesson-context-plan">
                        <p class="text-xs font-semibold text-muted-foreground">Plano</p>
                        <p :class="textClasses">{{ entry.plan }}</p>
                    </div>

                    <div v-if="entry.homework" class="grid min-w-0 gap-0.5" data-testid="lesson-context-homework">
                        <p class="text-xs font-semibold text-muted-foreground">TPC</p>
                        <p :class="textClasses">{{ entry.homework }}</p>
                    </div>

                    <div v-if="entry.resources" class="grid min-w-0 gap-0.5" data-testid="lesson-context-resources">
                        <p class="text-xs font-semibold text-muted-foreground">Recursos</p>
                        <p :class="textClasses">{{ entry.resources }}</p>
                    </div>
                </article>

                <Link
                    :href="contextHistoryUrl(entry)"
                    class="flex min-h-11 w-fit items-center text-sm text-muted-foreground underline underline-offset-2 hover:text-foreground sm:min-h-0"
                    data-testid="lesson-context-history"
                >
                    Ver histórico em Aulas e Sumários
                </Link>
            </template>
        </div>
    </section>
</template>
