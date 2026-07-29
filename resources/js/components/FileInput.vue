<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();

defineEmits<{
    (e: 'change', event: Event): void;
}>();
</script>

<template>
    <input
        type="file"
        data-slot="file-input"
        :class="
            cn(
                'h-9 w-full min-w-0 cursor-pointer rounded-md border border-input bg-transparent text-sm shadow-xs transition-[color,box-shadow] outline-none file:mr-3 file:h-full file:cursor-pointer file:rounded-l-md file:rounded-r-none file:border-0 file:bg-primary file:px-3 file:font-medium file:text-primary-foreground hover:file:bg-primary/90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
                'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                'aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40',
                props.class,
            )
        "
        v-bind="$attrs"
        @change="$emit('change', $event)"
    />
</template>
