<script setup lang="ts">
/**
 * EDITAR O SUMÁRIO NO CARTÃO (0.158.0) — e só o sumário.
 *
 * Grava pela rota própria `PATCH lessons/{lesson}/summary/content`, que só
 * aceita o texto e a versão lida: as notas do professor, os recursos e o TPC
 * continuam na página da aula e nunca são tocados daqui. As regras de sempre
 * vêm do servidor (SaveLessonSummary): gravar prepara uma aula por preparar, e
 * editar uma aula lecionada fica registado como revisão — o editor só avisa.
 *
 * O texto cresce com o campo (sem scroll interno). Ctrl+Enter guarda; Esc
 * cancela. Enquanto grava, o texto fica só de leitura, para o que se vê ser
 * exatamente o que foi enviado.
 */
import {
    CalendarClock,
    Check,
    CircleAlert,
    History,
    Info,
    Plus,
    Presentation,
    Save,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import LessonSummaryConflict from '@/components/lessons/week/LessonSummaryConflict.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    appendEventToSummary,
    LESSON_SUMMARY_MAX_LENGTH,
    summaryAppendBlockedReason,
} from '@/lib/lessonDayEvents';
import { isTaughtLesson } from '@/lib/lessons';
import type { WeekLesson } from '@/lib/lessons';

const props = defineProps<{
    lesson: WeekLesson;
    modelValue: string;
    original: string;
    saving: boolean;
    error: string | null;
    /** Informação neutra depois de uma decisão no conflito (p. ex. textos combinados). */
    notice?: string | null;
    conflict: { stored: string } | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
    save: [];
    cancel: [];
    project: [];
    combine: [];
    'keep-mine': [];
    'use-stored': [];
}>();

const textarea = ref<HTMLTextAreaElement | null>(null);
const conflictPanel = ref<InstanceType<typeof LessonSummaryConflict> | null>(
    null,
);
const fieldId = computed(() => `summary-editor-${props.lesson.ulid}`);
const dirty = computed(() => props.modelValue !== props.original);

const note = computed(() => {
    if (isTaughtLesson(props.lesson)) {
        return {
            icon: History,
            text: 'A aula já foi lecionada: guardar fica registado como revisão do sumário.',
        };
    }

    if (props.lesson.outcome === 'teacher_absent') {
        return {
            icon: Info,
            text: 'Professor ausente: o planeamento desta aula já passou para a aula seguinte.',
        };
    }

    if (props.lesson.status === 'preparation') {
        return { icon: Info, text: 'Ao guardar, a aula passa a «Preparada».' };
    }

    return null;
});

function autosize(): void {
    const element = textarea.value;

    if (element === null) {
        return;
    }

    element.style.height = 'auto';
    element.style.height = `${element.scrollHeight + 2}px`;
}

watch(
    () => props.modelValue,
    () => nextTick(autosize),
);

watch(
    () => props.conflict,
    (conflict) => {
        if (conflict !== null) {
            nextTick(() => conflictPanel.value?.focus());
        }
    },
);

onMounted(autosize);

function onInput(event: Event): void {
    emit('update:modelValue', (event.target as HTMLTextAreaElement).value);
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
        event.preventDefault();
        emit('save');
    } else if (event.key === 'Escape') {
        event.preventDefault();
        emit('cancel');
    }
}

function eventBlocked(
    event: WeekLesson['day_events'][number],
): 'already_present' | 'too_long' | null {
    return summaryAppendBlockedReason(props.modelValue, event);
}

function addEvent(event: WeekLesson['day_events'][number]): void {
    if (eventBlocked(event) !== null) {
        return;
    }

    emit('update:modelValue', appendEventToSummary(props.modelValue, event));
    nextTick(() => textarea.value?.focus());
}

function scheduleLabel(event: WeekLesson['day_events'][number]): string {
    if (event.all_day) {
        return 'Todo o dia';
    }

    if (event.starts_at === null) {
        return '';
    }

    return event.ends_at
        ? `${event.starts_at}–${event.ends_at}`
        : event.starts_at;
}

defineExpose({
    focus: () => {
        textarea.value?.focus({ preventScroll: true });
        const length = textarea.value?.value.length ?? 0;
        textarea.value?.setSelectionRange(length, length);
    },
});
</script>

<template>
    <div class="grid max-w-[78ch] gap-2" data-testid="summary-editor">
        <label :for="fieldId" class="text-sm font-semibold">Sumário</label>
        <p
            v-if="note"
            class="flex items-start gap-1.5 text-[13px] leading-5 text-muted-foreground"
        >
            <component
                :is="note.icon"
                class="mt-0.5 size-3.5 shrink-0"
                aria-hidden="true"
            />{{ note.text }}
        </p>
        <textarea
            :id="fieldId"
            ref="textarea"
            :value="modelValue"
            :maxlength="LESSON_SUMMARY_MAX_LENGTH"
            :readonly="saving"
            :aria-busy="saving || undefined"
            :aria-invalid="error ? true : undefined"
            :aria-describedby="`${fieldId}-status ${fieldId}-help${error ? ` ${fieldId}-error` : ''}`"
            rows="5"
            placeholder="Escreve o sumário desta aula…"
            class="min-h-36 w-full resize-none overflow-hidden rounded-[10px] border border-input bg-background px-3.5 py-3 text-base leading-relaxed shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
            @input="onInput"
            @keydown="onKeydown"
        />
        <p
            v-if="error"
            :id="`${fieldId}-error`"
            role="alert"
            class="flex items-start gap-1.5 text-sm font-semibold text-destructive"
        >
            <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{
                error
            }}
        </p>

        <p
            v-if="notice && !conflict"
            role="status"
            class="flex items-start gap-1.5 text-sm text-foreground"
            data-testid="summary-editor-notice"
        >
            <Info
                class="mt-0.5 size-4 shrink-0 text-primary"
                aria-hidden="true"
            />{{ notice }}
        </p>

        <LessonSummaryConflict
            v-if="conflict"
            ref="conflictPanel"
            :draft="modelValue"
            :stored="conflict.stored"
            @combine="emit('combine')"
            @keep-mine="emit('keep-mine')"
            @use-stored="emit('use-stored')"
        />

        <ul
            v-if="lesson.day_events.length > 0"
            class="grid gap-2"
            aria-label="Acontecimentos do dia"
        >
            <li
                v-for="event in lesson.day_events"
                :key="event.ulid"
                class="flex flex-col gap-2 rounded-lg border border-dashed p-2.5 text-sm sm:flex-row sm:items-center sm:justify-between"
            >
                <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <CalendarClock
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span
                        v-if="event.type_label"
                        class="text-xs font-semibold text-muted-foreground"
                        >{{ event.type_label }}</span
                    >
                    <span class="font-medium">{{ event.title }}</span>
                    <span class="text-[13px] text-muted-foreground">{{
                        scheduleLabel(event)
                    }}</span>
                </span>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="min-h-11 sm:min-h-8"
                    :disabled="eventBlocked(event) !== null || saving"
                    @click="addEvent(event)"
                >
                    <Check
                        v-if="eventBlocked(event) === 'already_present'"
                        class="size-4"
                        aria-hidden="true"
                    />
                    <Plus v-else class="size-4" aria-hidden="true" />
                    {{
                        eventBlocked(event) === 'already_present'
                            ? 'Já no sumário'
                            : 'Adicionar ao sumário'
                    }}
                </Button>
            </li>
        </ul>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <Button
                type="button"
                class="min-h-11 flex-1 sm:min-h-9 sm:flex-none"
                :disabled="saving"
                data-testid="summary-save"
                @click="emit('save')"
            >
                <Spinner v-if="saving" />
                <Save v-else class="size-4" aria-hidden="true" />
                {{ saving ? 'A guardar…' : 'Guardar' }}
            </Button>
            <Button
                type="button"
                variant="outline"
                class="min-h-11 flex-1 sm:min-h-9 sm:flex-none"
                :disabled="saving"
                data-testid="summary-cancel"
                @click="emit('cancel')"
            >
                Cancelar
            </Button>
            <Button
                type="button"
                variant="outline"
                class="min-h-11 flex-1 sm:min-h-9 sm:flex-none"
                :disabled="saving"
                aria-label="Projetar sumário"
                data-testid="summary-project"
                @click="emit('project')"
            >
                <Presentation class="size-4" aria-hidden="true" />
                Projetar
            </Button>
            <span
                :id="`${fieldId}-status`"
                aria-live="polite"
                :class="[
                    'inline-flex items-center gap-1.5 text-[13px] font-semibold',
                    dirty && !saving ? 'text-primary' : 'text-muted-foreground',
                ]"
                data-testid="summary-editor-status"
            >
                <template v-if="saving">A guardar…</template>
                <template v-else-if="dirty"
                    ><span
                        class="size-2 rounded-full bg-current"
                        aria-hidden="true"
                    />Alterações por guardar</template
                >
                <template v-else>Sem alterações</template>
            </span>
            <span
                class="w-full text-xs text-muted-foreground tabular-nums sm:ml-auto sm:w-auto"
            >
                {{ modelValue.length.toLocaleString('pt-PT') }} /
                {{ LESSON_SUMMARY_MAX_LENGTH.toLocaleString('pt-PT') }}
            </span>
        </div>
        <p :id="`${fieldId}-help`" class="text-xs text-muted-foreground">
            Ctrl+Enter guarda · Esc cancela. Guarda só o sumário: as notas do
            professor, os recursos e o TPC ficam como estão, na página da aula.
            «Projetar» mostra o texto como está, sem o guardar.
        </p>
    </div>
</template>
