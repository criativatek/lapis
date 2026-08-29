<script setup lang="ts">
/**
 * A real screenshot of the application in a thin frame. No fake browser
 * chrome: the picture is the product, the frame just keeps it off the ground.
 */
defineProps<{
    src: string;
    alt: string;
    width: number;
    height: number;
    eager?: boolean;
}>();
</script>

<template>
    <figure
        class="overflow-hidden rounded-2xl border border-black/10 bg-white shadow-[0_30px_80px_-40px_rgba(15,23,42,0.5)]"
    >
        <img
            :src="src"
            :alt="alt"
            :width="width"
            :height="height"
            :loading="eager ? 'eager' : 'lazy'"
            decoding="async"
            class="block h-auto w-full"
        />
    </figure>
</template>
