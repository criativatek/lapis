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
        <!-- The slogan is 38 characters: it does not fit one line in the
             16rem sidebar, and truncating it would cut the very sentence the
             brand stands for. Each sentence is an inline-block and the width is
             capped, so it always breaks between them, never mid-sentence, and
             never gets an ellipsis. -->
        <span
            class="mt-0.5 max-w-44 text-[0.6875rem] leading-snug text-pretty text-muted-foreground"
            data-testid="brand-slogan"
            ><span class="inline-block">A tecnologia apoia.</span>{{ ' '
            }}<span class="inline-block">O professor transforma.</span></span
        >
    </div>
</template>
