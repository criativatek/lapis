<script setup lang="ts">
/**
 * Cabeçalho de página com sítio para as acções.
 *
 * O `Heading.vue` (84 usos) é só título+descrição, e cada página que precisa
 * de um botão à direita inventa o seu `flex justify-between` — foi um dos
 * quatro padrões repetidos que o plano «mais cor» mandou componentizar. Este
 * NÃO substitui o Heading: coexiste, com a mesma tipografia, e as páginas
 * migram quando lhes tocar (migração oportunista, nunca em massa).
 */
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header
        class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2"
        :class="variant === 'small' ? '' : 'mb-6'"
    >
        <div class="min-w-0 space-y-0.5">
            <h2
                :class="
                    variant === 'small'
                        ? 'mb-0.5 text-base font-medium'
                        : 'text-xl font-semibold tracking-tight'
                "
            >
                {{ title }}
            </h2>
            <p v-if="description" class="text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>

        <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
