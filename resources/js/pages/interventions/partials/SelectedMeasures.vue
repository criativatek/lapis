<script setup lang="ts">
/**
 * «SELECIONADAS» — o que o professor já escolheu, em primeiro lugar.
 *
 * A queixa de origem era esta: para saber o que já tinha escolhido, era
 * preciso procurar as checkboxes marcadas dentro de uma caixa com scroll.
 * Agora o que está escolhido aparece ANTES do catálogo, com o nome inteiro,
 * a natureza e o nível, e um botão de remover por cada um.
 *
 * Cada cartão mostra ícone + texto da família e o nível por extenso — a cor
 * não é a única distinção entre uma medida universal e uma seletiva.
 */

import { ClipboardCheck, GraduationCap, HeartHandshake, LifeBuoy, X } from '@lucide/vue';
import { computed } from 'vue';
import type { CatalogueType, FamilyIcon } from '@/lib/interventionPresentation';
import { presentType } from '@/lib/interventionPresentation';

const props = defineProps<{
    types: CatalogueType[];
    selected: string[];
    /** Ao editar há sempre exactamente uma e não faz sentido removê-la. */
    removable: boolean;
}>();

const emit = defineEmits<{ remove: [string] }>();

const ICONS: Record<FamilyIcon, unknown> = {
    HeartHandshake,
    GraduationCap,
    ClipboardCheck,
    LifeBuoy,
};

/**
 * Pela ordem por que o professor as escolheu, não pela ordem do catálogo:
 * a última que acrescentou é a que ele está à espera de ver aparecer.
 *
 * Um valor que não exista no catálogo é ignorado em silêncio em vez de
 * desenhar um cartão vazio.
 */
const cards = computed(() =>
    props.selected
        .map((value) => props.types.find((type) => type.value === value))
        .filter((type): type is CatalogueType => type !== undefined)
        .map((type) => presentType(type)),
);
</script>

<template>
    <div class="space-y-2">
        <h3 class="text-sm font-medium">
            Selecionadas
            <span class="ml-1 text-xs font-normal text-muted-foreground">({{ cards.length }})</span>
        </h3>

        <p v-if="cards.length === 0" class="rounded-lg border border-dashed border-border px-3 py-4 text-sm text-muted-foreground">
            Ainda não escolheu nenhuma. Use a pesquisa abaixo.
        </p>

        <ul v-else class="space-y-1.5">
            <li
                v-for="card in cards"
                :key="card.value"
                class="flex items-start gap-2.5 rounded-lg border border-primary/40 bg-primary/5 p-2.5"
            >
                <component :is="ICONS[card.familyIcon]" class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />

                <div class="min-w-0 flex-1 space-y-1">
                    <p class="text-sm leading-snug break-words">{{ card.label }}</p>
                    <p class="flex flex-wrap items-center gap-1.5">
                        <span class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ card.familyLabel }}</span>
                        <span v-if="card.levelLabel" class="rounded-full px-2 py-0.5 text-xs" :class="card.toneClasses">
                            {{ card.levelLabel }}<template v-if="card.isSuggestedLevel"> (possível)</template>
                        </span>
                        <span class="text-xs text-muted-foreground">{{ card.contextLabel }}</span>
                    </p>
                </div>

                <button
                    v-if="removable"
                    type="button"
                    class="flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-muted/60 hover:text-red-600 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="`Remover ${card.label}`"
                    @click="emit('remove', card.value)"
                >
                    <X class="size-4" aria-hidden="true" />
                </button>
            </li>
        </ul>
    </div>
</template>
