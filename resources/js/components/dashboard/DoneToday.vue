<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, ChevronDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import SectionHeading from '@/components/dashboard/SectionHeading.vue';
import type { DoneItem } from '@/lib/dashboard';
import { doneClassHint } from '@/lib/dashboard';

const props = defineProps<{ items: DoneItem[] }>();

/** Três linhas à vista; o resto do dia abre-se, nunca se esconde. */
const SHOWN = 3;
const expanded = ref(false);
const visible = computed(() => (expanded.value ? props.items : props.items.slice(0, SHOWN)));
const hidden = computed(() => Math.max(0, props.items.length - SHOWN));
const note = computed(() => {
    const total = props.items.length;

    if (total === 0) {
        return '0 atividades';
    }

    return total <= SHOWN ? `${total} ${total === 1 ? 'atividade' : 'atividades'}` : `${total} atividades · ${expanded.value ? 'o dia inteiro' : 'as 3 mais recentes'}`;
});
</script>

<template>
    <section class="flex flex-col gap-2" aria-labelledby="dash-done" data-testid="done-today">
        <SectionHeading id="dash-done" title="Arrumado hoje" :note="note" />

        <!-- Vazio é só uma linha: sem cartão nem espaço reservado, para o bloco ocupar a sua própria linha curta. -->
        <p v-if="items.length === 0" class="text-sm text-muted-foreground">Ainda nada registado hoje.</p>

        <template v-else>
            <ol class="divide-y divide-border border-y border-border">
                <li v-for="item in visible" :key="`${item.kind}-${item.at}-${item.title}`">
                    <Link :href="item.href" class="grid grid-cols-[22px_1fr_auto] items-center gap-2.5 py-2 pr-1 hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                        <!-- O ÂMBAR É DO FEITO: o visto leva a cor da marca. -->
                        <span class="grid size-[22px] place-items-center rounded-md bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200" aria-hidden="true"><Check class="size-3" /></span>
                        <span class="min-w-0 truncate text-sm">{{ item.title }}<span v-if="doneClassHint(item)" class="text-muted-foreground"> · {{ doneClassHint(item) }}</span></span>
                        <span class="font-serif text-[13.5px] text-muted-foreground italic tabular-nums">às {{ item.time }}</span>
                    </Link>
                </li>
            </ol>
            <button
                v-if="hidden > 0 || expanded"
                type="button"
                class="mt-1 inline-flex h-8 items-center gap-1.5 self-start rounded-md border border-border px-2.5 text-[13px] font-semibold text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :aria-expanded="expanded"
                data-testid="done-toggle"
                @click="expanded = !expanded"
            >
                <ChevronDown class="size-3.5 transition-transform" :class="expanded ? 'rotate-180' : ''" aria-hidden="true" />
                {{ expanded ? 'Mostrar só as mais recentes' : `Mostrar o resto do dia (${hidden})` }}
            </button>
        </template>
    </section>
</template>
