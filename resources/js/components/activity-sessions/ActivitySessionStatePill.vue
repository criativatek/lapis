<script setup lang="ts">
/**
 * O ESTADO DE UMA SESSÃO de uma «outra atividade» — a pílula redonda, com ícone
 * e rótulo por extenso (o tom é reforço, nunca a mensagem). Preparada azul,
 * realizada verde, não realizada âmbar (nunca vermelha: não aconteceu, não é um
 * erro), por realizar neutra.
 */
import { computed } from 'vue';
import {
    activitySessionIcon,
    activitySessionToneKey,
} from '@/lib/activitySessions';
import type { ActivitySessionPresentation } from '@/lib/activitySessions';
import { statusToneClasses } from '@/lib/statusTone';

const props = withDefaults(
    defineProps<{
        presentation: ActivitySessionPresentation;
        label: string;
        size?: 'md' | 'sm';
    }>(),
    { size: 'md' },
);

const icon = computed(() => activitySessionIcon(props.presentation));
</script>

<template>
    <span
        data-testid="activity-session-state"
        :data-state="presentation"
        :class="[
            'inline-flex max-w-full items-center gap-1.5 rounded-full font-semibold',
            size === 'sm'
                ? 'px-2 py-0.5 text-xs leading-4'
                : 'py-0.5 pr-2.5 pl-2 text-[13px] leading-[18px]',
            statusToneClasses(activitySessionToneKey(presentation)),
        ]"
    >
        <component
            :is="icon"
            :class="size === 'sm' ? 'size-3.5' : 'size-[15px]'"
            class="shrink-0"
            aria-hidden="true"
        />
        <span class="min-w-0">{{ label }}</span>
    </span>
</template>
