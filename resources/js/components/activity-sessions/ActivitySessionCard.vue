<script setup lang="ts">
/**
 * O CARTÃO DE UMA SESSÃO de uma «outra atividade» em Aulas e Sumários (0.170.0).
 *
 * Deliberadamente outro que o cartão de uma aula: tracejado, como o bloco da
 * atividade no Horário, sem barra de turma e sem fecho rápido nem seleção em
 * lote — não é uma aula. Mostra o nome da atividade, o tipo, a turma (se
 * houver), o estado e o sumário por inteiro (quebras de linha e URLs longos
 * incluídos), e abre o registo da sessão.
 */
import { Pencil, Plus } from '@lucide/vue';
import { computed } from 'vue';
import ActivitySessionStatePill from '@/components/activity-sessions/ActivitySessionStatePill.vue';
import { Button } from '@/components/ui/button';
import { activitySessionNumberLabel } from '@/lib/activitySessions';
import type { ActivitySessionItem } from '@/lib/activitySessions';

const props = defineProps<{ session: ActivitySessionItem }>();

defineEmits<{ open: [] }>();

const numberLabel = computed(() => activitySessionNumberLabel(props.session));
const editing = computed(
    () => props.session.has_summary || props.session.status !== 'planned',
);
const canOpen = computed(
    () => props.session.session_ulid !== null || props.session.registrable,
);
</script>

<template>
    <article
        :id="`atividade-${session.key}`"
        data-testid="activity-session-card"
        class="@container relative grid gap-3 rounded-[10px] border border-dashed border-muted-foreground/40 bg-muted/20 p-3 @xl:grid-cols-[minmax(0,12rem)_minmax(0,1fr)_auto] @xl:items-start"
    >
        <div class="grid min-w-0 gap-1">
            <time class="text-sm font-medium tabular-nums"
                >{{ session.start_time }}–{{ session.end_time }}</time
            >
            <span class="text-sm font-medium wrap-anywhere">{{
                session.title
            }}</span>
            <span class="text-xs text-muted-foreground"
                >{{ session.kind_label
                }}<template v-if="session.school_class">
                    · {{ session.school_class.label }}</template
                ><template v-if="numberLabel"> · {{ numberLabel }}</template>
            </span>
        </div>

        <p
            v-if="session.has_summary"
            class="max-w-[72ch] text-sm leading-relaxed wrap-anywhere whitespace-pre-line"
            data-testid="activity-session-summary-text"
        >
            {{ session.summary }}
        </p>
        <p v-else class="text-sm text-muted-foreground">Sem sumário.</p>

        <div
            class="flex flex-wrap items-center gap-2 @xl:flex-col @xl:items-end"
        >
            <ActivitySessionStatePill
                :presentation="session.presentation"
                :label="session.presentation_label"
                size="sm"
            />
            <Button
                v-if="canOpen"
                type="button"
                variant="outline"
                size="sm"
                class="min-h-11 sm:min-h-9"
                data-testid="activity-session-open"
                @click="$emit('open')"
            >
                <component
                    :is="editing ? Pencil : Plus"
                    class="size-4"
                    aria-hidden="true"
                />
                {{ editing ? 'Editar sumário' : 'Registar sumário' }}
            </Button>
        </div>
    </article>
</template>
