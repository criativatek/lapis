<script setup lang="ts">
defineProps<{ label: string; value: string | number; hint?: string }>();
</script>

<template>
    <div class="rounded-xl border border-border bg-card p-4">
        <p class="text-sm text-muted-foreground">{{ label }}</p>
        <p class="mt-1 text-2xl font-semibold tabular-nums">{{ value }}</p>
        <p v-if="hint" class="mt-1 text-xs text-muted-foreground">{{ hint }}</p>
    </div>
</template>
