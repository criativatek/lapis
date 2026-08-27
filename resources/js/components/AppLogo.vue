<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <div
        class="flex aspect-square size-8 items-center justify-center rounded-md bg-primary text-primary-foreground"
    >
        <AppLogoIcon class="size-5" />
    </div>
    <div class="ml-1 grid flex-1 text-left">
        <span
            class="truncate text-base leading-none font-semibold tracking-tight"
            >Lapispro</span
        >
        <span class="truncate text-xs text-muted-foreground"
            >Mais simples. Mais tempo.</span
        >
    </div>
</template>
