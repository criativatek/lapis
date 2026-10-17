<script setup lang="ts">
import { CalendarClock, CalendarX2, Check, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    appendEventToSummary,
    coverageLabel,
    eventTimeLabel,
    splitCoinciding,
    summaryAppendBlockedReason,
} from '@/lib/lessonDayEvents';
import type { DayEventCoverage } from '@/lib/lessonDayEvents';
import { SURFACE } from '@/lib/surfaces';

export type DayEvent = {
    ulid: string;
    title: string;
    starts_at: string | null;
    ends_at: string | null;
    all_day: boolean;
    notes: string | null;
    type_label?: string;
    /** Cruzamento das horas com as da aula (0.171.0), calculado no servidor. */
    coincides?: boolean;
    coverage?: DayEventCoverage;
};

/**
 * Os acontecimentos do calendário do professor no dia da aula (0.156.0), com a
 * COINCIDÊNCIA das horas (0.171.0).
 *
 * DUAS ações, sempre distintas — e nunca se confundem:
 *  - «Incluir no sumário» acrescenta a linha da atividade ao texto que está a
 *    ser preparado. Mantém o plano inteiro, não grava sozinho e não muda o
 *    estado nem a posição da aula;
 *  - «Registar e adiar a preparação» é para quando a atividade OCUPA a aula:
 *    abre a pré-visualização, e nada muda até o professor confirmar.
 *
 * Tom `sky` (informação): o âmbar nunca é aviso (DESIGN.md).
 */
const props = defineProps<{
    events: DayEvent[];
    summaryContent: string;
    /** A aula pode registar um resultado (`lesson.can_record_outcome`). */
    canPostpone?: boolean;
}>();
const emit = defineEmits<{ append: [content: string, focusSummary: boolean]; postpone: [event: DayEvent] }>();

const groups = computed(() => splitCoinciding(props.events));

const blockedReasons = computed(() =>
    Object.fromEntries(
        props.events.map((event) => [
            event.ulid,
            summaryAppendBlockedReason(props.summaryContent, event),
        ]),
    ),
);

function addToSummary(event: DayEvent): void {
    if (blockedReasons.value[event.ulid] !== null) {
        return;
    }

    emit('append', appendEventToSummary(props.summaryContent, event), true);
}
</script>

<template>
    <div v-if="events.length > 0" class="space-y-3">
        <section
            v-if="groups.coinciding.length > 0"
            :class="['space-y-3 rounded-xl border p-4', SURFACE.sky]"
            data-testid="coinciding-events"
        >
            <h2 class="flex items-center gap-2 text-sm font-semibold">
                <CalendarClock class="size-4" aria-hidden="true" />
                {{
                    groups.coinciding.length === 1
                        ? 'Esta aula coincide com uma atividade do calendário'
                        : 'Esta aula coincide com atividades do calendário'
                }}
            </h2>
            <p class="text-sm text-muted-foreground">
                «Incluir no sumário» acrescenta a atividade ao texto que estás a preparar e mantém o plano inteiro.
                <template v-if="canPostpone">
                    «Registar e adiar a preparação» é para quando a atividade ocupa a aula: a preparação avança para
                    a aula seguinte, e nada muda até confirmares.
                </template>
            </p>
            <ul class="space-y-3">
                <li
                    v-for="event in groups.coinciding"
                    :key="event.ulid"
                    data-testid="day-event"
                    class="space-y-3 rounded-lg border bg-background/60 p-3 dark:bg-background/30"
                >
                    <div class="space-y-1">
                        <p class="font-medium">{{ event.title }}</p>
                        <p class="text-sm text-muted-foreground">
                            <template v-if="event.type_label">{{ event.type_label }} · </template>{{ eventTimeLabel(event) }}
                            <template v-if="coverageLabel(event.coverage)"> · {{ coverageLabel(event.coverage) }}</template>
                        </p>
                        <p v-if="event.notes" class="whitespace-pre-line text-sm text-muted-foreground">{{ event.notes }}</p>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 w-full sm:w-auto"
                            data-testid="include-in-summary"
                            :disabled="blockedReasons[event.ulid] !== null"
                            @click="addToSummary(event)"
                        >
                            <Check v-if="blockedReasons[event.ulid] === 'already_present'" class="size-4" />
                            <Plus v-else class="size-4" />
                            {{ blockedReasons[event.ulid] === 'already_present' ? 'Já no sumário' : 'Incluir no sumário' }}
                        </Button>
                        <Button
                            v-if="canPostpone"
                            type="button"
                            class="min-h-11 w-full sm:w-auto"
                            data-testid="postpone-preparation"
                            @click="emit('postpone', event)"
                        >
                            <CalendarX2 class="size-4" />
                            Registar e adiar a preparação
                        </Button>
                    </div>
                    <p v-if="blockedReasons[event.ulid] === 'too_long'" class="text-xs text-muted-foreground">
                        O sumário ficaria demasiado longo
                    </p>
                </li>
            </ul>
        </section>

        <section v-if="groups.others.length > 0" class="space-y-3 rounded-xl border bg-card p-4" data-testid="other-events">
            <h2 class="flex items-center gap-2 text-sm font-semibold">
                <CalendarClock class="size-4 text-muted-foreground" aria-hidden="true" />
                {{ groups.coinciding.length > 0 ? 'Outros acontecimentos do dia' : 'Acontecimentos do dia' }}
            </h2>
            <ul class="space-y-3">
                <li
                    v-for="event in groups.others"
                    :key="event.ulid"
                    data-testid="day-event"
                    class="flex flex-col gap-2 rounded-lg border border-dashed p-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="space-y-1">
                        <p class="font-medium">{{ event.title }}</p>
                        <p class="text-sm text-muted-foreground">
                            <template v-if="event.type_label">{{ event.type_label }} · </template>{{ eventTimeLabel(event) }}
                        </p>
                        <p v-if="event.notes" class="whitespace-pre-line text-sm text-muted-foreground">{{ event.notes }}</p>
                    </div>
                    <div class="flex shrink-0 flex-col items-stretch gap-1 sm:items-end">
                        <!-- Alvo táctil ≥44px no telemóvel (DESIGN.md); no ecrã largo mantém o tamanho sm. -->
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="min-h-11 w-full sm:min-h-0 sm:w-auto"
                            data-testid="include-in-summary"
                            :disabled="blockedReasons[event.ulid] !== null"
                            @click="addToSummary(event)"
                        >
                            <Check v-if="blockedReasons[event.ulid] === 'already_present'" class="size-4" />
                            <Plus v-else class="size-4" />
                            {{ blockedReasons[event.ulid] === 'already_present' ? 'Já no sumário' : 'Incluir no sumário' }}
                        </Button>
                        <p v-if="blockedReasons[event.ulid] === 'too_long'" class="text-xs text-muted-foreground">
                            O sumário ficaria demasiado longo
                        </p>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
