<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';

/**
 * Wrapper do <select> nativo com o mesmo casco visual dos filtros de
 * assessments/Index.vue e students/Index.vue: appearance-none + chevron
 * absoluto por cima + o par de classes de Input. O consumidor continua a
 * escrever as próprias <option> via slot — este componente só veste a casca.
 */
defineProps<{ disabled?: boolean }>();
defineOptions({ inheritAttrs: false });

const modelValue = defineModel<string | number | null>({ required: true });
</script>

<template>
    <div class="relative">
        <select
            v-model="modelValue"
            v-bind="$attrs"
            :disabled="disabled"
            class="h-9 w-full appearance-none rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-default disabled:opacity-50"
        >
            <slot />
        </select>
        <ChevronDown class="pointer-events-none absolute top-1/2 right-2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
    </div>
</template>
