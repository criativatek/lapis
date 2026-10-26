<script setup lang="ts">
/**
 * O cabeçalho de um bloco do painel: título curto, a régua âmbar da marca
 * (só nos blocos que pedem decisão — `accent`) e, à direita, um facto em
 * serifa itálica ou uma ligação.
 */
defineProps<{
    id: string;
    title: string;
    accent?: boolean;
    note?: string | null;
}>();
</script>

<template>
    <div class="flex items-baseline justify-between gap-3">
        <div>
            <h2 :id="id" class="text-[15px] leading-5 font-semibold">{{ title }}</h2>
            <span v-if="accent" class="mt-1 block h-0.5 w-12 rounded bg-(--brand-amber)" aria-hidden="true"></span>
        </div>
        <span v-if="note" class="font-serif text-sm text-muted-foreground italic">{{ note }}</span>
        <slot v-else name="aside" />
    </div>
</template>
