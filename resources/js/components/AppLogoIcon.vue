<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- LÁPIS mark: an L formed by a pencil (amber body) and a clock hand,
         "mais tempo para ensinar". Uses currentColor for the L stroke so it
         inverts with the foreground; the pencil body stays amber. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 40"
        fill="none"
        :class="className"
        v-bind="$attrs"
    >
        <path
            d="M9 5v22"
            stroke="#F5B855"
            stroke-width="6"
            stroke-linecap="round"
        />
        <path d="M6.2 5.4 9 1l2.8 4.4z" fill="currentColor" />
        <path
            d="M9 30h16"
            stroke="currentColor"
            stroke-width="6"
            stroke-linecap="round"
        />
        <path
            d="M18 15v7l5 4"
            stroke="currentColor"
            stroke-width="3"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
        <circle cx="18" cy="22" r="2.6" fill="#F5B855" />
    </svg>
</template>
