<script setup lang="ts">
/**
 * O ESTADO DA AULA — uma pílula redonda, com ícone e rótulo por extenso.
 *
 * A forma é deliberadamente outra que a da identidade da turma (etiqueta
 * quadrada, à esquerda): o estado vive à direita, redondo, e os tons são os de
 * `statusTone` (os mesmos de toda a aplicação). Um só estado por aula —
 * `lessonDisplayState`: o resultado ganha; sem resultado, fica a preparação.
 */
import { computed } from 'vue';
import { lessonDisplayState } from '@/lib/lessons';
import type { LessonStateSource } from '@/lib/lessons';
import { lessonStateIcon } from '@/lib/lessonStateIcons';
import { statusToneClasses } from '@/lib/statusTone';

const props = withDefaults(
    defineProps<{ lesson: LessonStateSource; size?: 'md' | 'sm' }>(),
    { size: 'md' },
);

const state = computed(() => lessonDisplayState(props.lesson));
const icon = computed(() => lessonStateIcon(state.value.value));
</script>

<template>
    <span
        data-testid="lesson-state"
        :class="[
            'inline-flex max-w-full items-center gap-1.5 rounded-full font-semibold',
            size === 'sm'
                ? 'px-2 py-0.5 text-xs leading-4'
                : 'py-0.5 pr-2.5 pl-2 text-[13px] leading-[18px]',
            statusToneClasses(state.value),
        ]"
    >
        <component
            :is="icon"
            :class="size === 'sm' ? 'size-3.5' : 'size-[15px]'"
            class="shrink-0"
            aria-hidden="true"
        />
        <span class="min-w-0">{{ state.label }}</span>
    </span>
</template>
