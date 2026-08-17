<script setup lang="ts">
/**
 * A numbered section marker — «01 · DISTRIBUIÇÃO PELA ESCALA».
 *
 * The numeral is what separates an infographic from a dashboard: it says the
 * page has an order and is meant to be read, rather than being a wall of
 * independent widgets. It is DECORATION, so it is hidden from assistive
 * technology; the heading it sits beside is the real one.
 */

withDefaults(
    defineProps<{
        /** «01», «02» — the reading order, not an identifier. */
        index: string;
        title: string;
        description?: string;
        /** Anchors the numeral to the accessible heading. */
        id?: string;
    }>(),
    { description: undefined, id: undefined },
);
</script>

<template>
    <div class="mb-5 flex items-start gap-3.5">
        <!-- The plate: a small block with a lit top edge and a shadow under it,
             the same solid the columns are drawn as. -->
        <span
            aria-hidden="true"
            class="relative mt-0.5 hidden shrink-0 select-none rounded-lg border border-border/70 bg-gradient-to-b from-muted/70 to-muted/30 px-2.5 py-1 text-[13px] font-semibold tabular-nums tracking-tight text-muted-foreground shadow-[0_2px_0_0_var(--border),0_4px_10px_-6px_rgba(0,0,0,0.35)] sm:inline-block dark:shadow-[0_2px_0_0_rgba(255,255,255,0.08),0_6px_14px_-8px_rgba(0,0,0,0.8)]"
        >
            {{ index }}
        </span>

        <div class="min-w-0">
            <h2 :id="id" class="text-sm font-semibold tracking-tight">
                <span aria-hidden="true" class="mr-1.5 text-muted-foreground sm:hidden">{{ index }}</span>
                {{ title }}
            </h2>
            <p v-if="description" class="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                {{ description }}
            </p>
            <slot name="description" />
        </div>

        <div class="ml-auto shrink-0">
            <slot name="aside" />
        </div>
    </div>
</template>
