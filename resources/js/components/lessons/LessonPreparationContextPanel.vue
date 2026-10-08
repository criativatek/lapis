<script setup lang="ts">
/**
 * «ANTES DESTA AULA» — o que se deu e o que já está planeado nas aulas
 * anteriores do mesmo público, para quem prepara esta. Só leitura.
 *
 * Duas versões da mesma lista: a completa (página da aula) e a compacta
 * (editor no cartão: data, estado e primeira linha). O estado diz sempre se a
 * aula foi LECIONADA ou só está PREPARADA — um plano não é matéria dada. A
 * cor é reforço: o selo leva sempre o texto.
 */
import { ChevronDown, History, RotateCw } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useLessonPreparationContext } from '@/composables/useLessonPreparationContext';
import { contextDayMonth, firstLine, isLongContextText } from '@/lib/lessonContext';
import type { LessonContextEntry } from '@/lib/lessonContext';
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
const { entries, hasMore, loading, error, load, loadMore } = useLessonPreparationContext(ulid);

const open = ref(props.defaultOpen);
const expanded = ref<Record<string, boolean>>({});
const panelId = computed(() => `lesson-context-${props.lessonUlid}`);

// Carrega ao montar, mesmo recolhido: o número no título diz se vale a pena abrir.
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

function heading(entry: LessonContextEntry): string {
    const when = new Date(entry.starts_at);

    return `${capitalizeFirst(weekdayDate.format(when))}, ${clock.format(when)}`;
}

function toggle(entry: LessonContextEntry): void {
    expanded.value = { ...expanded.value, [entry.ulid]: !expanded.value[entry.ulid] };
}
</script>

<template>
    <section class="rounded-xl border bg-card" :data-testid="compact ? 'lesson-context-compact' : 'lesson-context'">
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
                <History class="size-4 text-muted-foreground" aria-hidden="true" />
                Antes desta aula
                <span v-if="!loading && !error" class="font-normal text-muted-foreground">({{ entries.length }}{{ hasMore ? '+' : '' }})</span>
                <ChevronDown :class="['ml-auto size-4 transition-transform', open ? 'rotate-180' : '']" aria-hidden="true" />
            </button>
        </component>

        <div v-show="open" :id="panelId" class="grid gap-3 border-t px-4 py-3">
            <p v-if="loading && entries.length === 0" role="status" class="flex items-center gap-2 text-sm text-muted-foreground" data-testid="lesson-context-loading">
                <Spinner class="size-4" /> A carregar as aulas anteriores…
            </p>

            <div v-else-if="error" role="alert" class="flex flex-wrap items-center gap-2 text-sm" data-testid="lesson-context-error">
                <span class="text-destructive">Não foi possível carregar as aulas anteriores.</span>
                <Button type="button" variant="outline" size="sm" class="min-h-11 sm:min-h-8" @click="load">
                    <RotateCw class="size-4" aria-hidden="true" /> Tentar de novo
                </Button>
            </div>

            <p v-else-if="entries.length === 0" class="text-sm text-muted-foreground" data-testid="lesson-context-empty">
                Sem aulas anteriores com conteúdo nesta turma/grupo.
            </p>

            <ol v-else class="grid gap-3">
                <li
                    v-for="entry in entries"
                    :key="entry.ulid"
                    class="grid gap-1.5 rounded-lg border bg-background p-3"
                    data-testid="lesson-context-entry"
                >
                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-muted-foreground">
                        <strong class="font-semibold text-foreground">{{ compact ? contextDayMonth(entry.starts_at) : heading(entry) }}</strong>
                        <span v-if="entry.lesson_number !== null" class="tabular-nums">Lição {{ entry.lesson_number }}</span>
                        <Badge variant="secondary" data-testid="lesson-context-state" :class="statusToneClasses(entry.state)">{{
                            entry.state_label
                        }}</Badge>
                    </p>

                    <p v-if="compact" class="line-clamp-1 text-sm" data-testid="lesson-context-line">
                        {{ firstLine(entry.content) || 'Sem sumário escrito.' }}
                    </p>
                    <template v-else>
                        <p
                            v-if="entry.content.trim() === ''"
                            class="text-sm text-muted-foreground"
                        >
                            Sem sumário escrito.
                        </p>
                        <p
                            v-else
                            :class="['whitespace-pre-line text-sm leading-relaxed', isLongContextText(entry.content) && !expanded[entry.ulid] ? 'line-clamp-4' : '']"
                        >
                            {{ entry.content }}
                        </p>
                        <button
                            v-if="isLongContextText(entry.content)"
                            type="button"
                            class="min-h-11 w-fit text-sm font-medium text-primary underline underline-offset-2 sm:min-h-0"
                            :aria-expanded="Boolean(expanded[entry.ulid])"
                            @click="toggle(entry)"
                        >
                            {{ expanded[entry.ulid] ? 'Ver menos' : 'Ver mais' }}
                        </button>
                        <p v-if="entry.homework || entry.resources" class="text-xs text-muted-foreground">
                            <template v-if="entry.homework"><span class="font-semibold">TPC:</span> {{ entry.homework }}</template>
                            <template v-if="entry.homework && entry.resources"> · </template>
                            <template v-if="entry.resources"><span class="font-semibold">Recursos:</span> {{ entry.resources }}</template>
                        </p>
                    </template>
                </li>
            </ol>

            <Button
                v-if="hasMore && !error"
                type="button"
                variant="outline"
                size="sm"
                class="min-h-11 w-fit sm:min-h-8"
                :disabled="loading"
                data-testid="lesson-context-more"
                @click="loadMore"
            >
                <Spinner v-if="loading" class="size-4" />
                Mostrar mais
            </Button>
        </div>
    </section>
</template>
