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
        class="overflow-hidden rounded-[1.25rem] bg-white shadow-[0_40px_90px_-40px_rgba(15,23,42,0.55)] ring-1 ring-black/10 sm:rounded-3xl"
    >
        <div
            aria-hidden="true"
            class="flex items-center gap-1.5 border-b border-slate-200 bg-slate-100 px-4 py-2.5"
        >
            <span class="size-2.5 rounded-full bg-slate-300" />
            <span class="size-2.5 rounded-full bg-slate-300" />
            <span class="size-2.5 rounded-full bg-slate-300" />
            <span
                class="ml-3 hidden h-5 flex-1 rounded-md bg-white ring-1 ring-slate-200 sm:block"
            />
        </div>
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
