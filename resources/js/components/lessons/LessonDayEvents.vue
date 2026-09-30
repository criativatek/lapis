<script setup lang="ts">
import { CalendarClock, Check, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { appendEventToSummary, summaryAppendBlockedReason } from '@/lib/lessonDayEvents';

export type DayEvent = {
    ulid: string;
    title: string;
    starts_at: string | null;
    ends_at: string | null;
    all_day: boolean;
    notes: string | null;
    type_label?: string;
};

const props = defineProps<{ events: DayEvent[]; summaryContent: string }>();
const emit = defineEmits<{ append: [content: string, focusSummary: boolean] }>();

function scheduleLabel(event: DayEvent): string {
    if (event.all_day) {
        return 'Todo o dia';
    }

    if (event.starts_at === null) {
        return '';
    }

    return event.ends_at ? `${event.starts_at}–${event.ends_at}` : event.starts_at;
}

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
    <section v-if="events.length > 0" class="space-y-3 rounded-xl border bg-card p-4">
        <h2 class="flex items-center gap-2 text-sm font-semibold">
            <CalendarClock class="size-4 text-muted-foreground" />
            Acontecimentos do dia
        </h2>
        <ul class="space-y-3">
            <li
                v-for="event in events"
                :key="event.ulid"
                data-testid="day-event"
                class="flex flex-col gap-2 rounded-lg border border-dashed p-3 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="space-y-1">
                    <p class="font-medium">{{ event.title }}</p>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="event.type_label">{{ event.type_label }} · </template>{{ scheduleLabel(event) }}
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
                        :disabled="blockedReasons[event.ulid] !== null"
                        @click="addToSummary(event)"
                    >
                        <Check v-if="blockedReasons[event.ulid] === 'already_present'" class="size-4" />
                        <Plus v-else class="size-4" />
                        {{ blockedReasons[event.ulid] === 'already_present' ? 'Já no sumário' : 'Adicionar ao sumário' }}
                    </Button>
                    <p v-if="blockedReasons[event.ulid] === 'too_long'" class="text-xs text-muted-foreground">
                        O sumário ficaria demasiado longo
                    </p>
                </div>
            </li>
        </ul>
    </section>
</template>
