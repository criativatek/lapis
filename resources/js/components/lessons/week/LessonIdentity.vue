<script setup lang="ts">
/**
 * QUEM É A AULA: o nome da turma numa etiqueta com o tom guardado dela, e o
 * âmbito ao lado, por extenso.
 *
 * A cor nunca é a única distinção. O âmbito diz-se em texto e tem um contorno
 * próprio: contínuo na turma inteira, tracejado num grupo (T1, T2…), duplo
 * numa turma de apoio. Lê-se igual num ecrã monocromático.
 */
import { LifeBuoy, Split, Users } from '@lucide/vue';
import { computed } from 'vue';
import type { LessonScope } from '@/lib/lessons';
import {
    TURMA_BADGE,
    TURMA_BADGE_BORDER,
    TURMA_SWATCH,
} from '@/lib/turmaTones';
import type { TurmaTone } from '@/lib/turmaTones';

const props = withDefaults(
    defineProps<{
        classLabel: string;
        tone: TurmaTone;
        scope?: LessonScope | null;
        size?: 'md' | 'sm';
    }>(),
    { scope: null, size: 'md' },
);

const scopeIcon = computed(() => {
    if (props.scope?.kind === 'group') {
        return Split;
    }

    if (props.scope?.kind === 'support') {
        return LifeBuoy;
    }

    return Users;
});
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-1.5">
        <span
            data-testid="lesson-class-tag"
            :data-tone="tone"
            :class="[
                'inline-flex items-center gap-1.5 rounded-md border font-semibold whitespace-nowrap',
                TURMA_BADGE[tone],
                TURMA_BADGE_BORDER[tone],
                size === 'sm'
                    ? 'px-1.5 text-xs leading-5'
                    : 'px-2 text-[15px] leading-[22px]',
            ]"
        >
            <span
                :class="['size-2.5 shrink-0 rounded-[3px]', TURMA_SWATCH[tone]]"
                aria-hidden="true"
            />
            {{ classLabel }}
        </span>
        <span
            v-if="scope"
            data-testid="lesson-scope"
            :data-scope="scope.kind"
            :class="[
                'inline-flex items-center gap-1 rounded-md border-foreground/40 font-semibold whitespace-nowrap text-foreground',
                scope.kind === 'group'
                    ? 'border-[1.5px] border-dashed'
                    : scope.kind === 'support'
                      ? 'border-[3px] border-double'
                      : 'border-[1.5px] border-solid',
                size === 'sm'
                    ? 'px-1.5 text-xs leading-[18px]'
                    : 'px-2 text-[13px] leading-5',
            ]"
        >
            <component :is="scopeIcon" class="size-3.5" aria-hidden="true" />
            {{ scope.label }}
        </span>
    </span>
</template>
