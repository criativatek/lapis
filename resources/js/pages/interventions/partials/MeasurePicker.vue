<script setup lang="ts">
/**
 * ESCOLHER ESTRATÉGIAS E MEDIDAS — cards, não uma lista de checkboxes.
 *
 * O que estava aqui antes era uma caixa de 44px de altura com scroll
 * próprio, dentro de uma célula de grelha, com todos os tipos do catálogo
 * em linhas iguais. Funcionava e não se percebia nada: as categorias eram
 * uma `<legend>` cinzenta, o nível da medida não aparecia em lado nenhum e
 * o professor só sabia o que tinha escolhido se contasse as pastilhas.
 *
 * Aqui: pesquisa local, filtros por família, e cada opção é um card com o
 * seu nome, a sua categoria pedagógica e — quando o catálogo o dá — o nível
 * da medida.
 *
 * SEMÂNTICA A SÉRIO. Cada card É um `<input type=checkbox>` (ou `radio`, a
 * escolher um só) com uma `<label>` à volta. O input fica visualmente
 * escondido mas presente: teclado, leitor de ecrã e estado «selecionado»
 * vêm de graça e correctos, em vez de um `div` com `aria-pressed` colado
 * por cima. O anel de foco é desenhado no card através de
 * `has-[:focus-visible]`, por isso navegar por Tab vê-se.
 *
 * A COR NUNCA ESTÁ SOZINHA: o nível aparece sempre por extenso na pastilha,
 * a família tem ícone E texto, e o estado selecionado tem um ✓ além da
 * moldura.
 *
 * SEM PEDIDOS AO SERVIDOR. `types` já veio todo no payload da página; a
 * pesquisa e os filtros são sobre esse array.
 */

import { ClipboardCheck, GraduationCap, HeartHandshake, LifeBuoy, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { CatalogueType, FamilyIcon, MeasureFamily, PresentedType } from '@/lib/interventionPresentation';
import { groupByFamily, matchesSearch } from '@/lib/interventionPresentation';

const props = defineProps<{
    types: CatalogueType[];
    /** Os valores escolhidos. Um só elemento quando `multiple` é falso. */
    modelValue: string[];
    /** Criar permite várias; editar diz respeito a uma medida só. */
    multiple: boolean;
    /** O tecto que o servidor impõe na criação em lote. */
    max?: number;
}>();

const emit = defineEmits<{ 'update:modelValue': [string[]] }>();

const ICONS: Record<FamilyIcon, unknown> = {
    HeartHandshake,
    GraduationCap,
    ClipboardCheck,
    LifeBuoy,
};

const search = ref('');
const familyFilter = ref<MeasureFamily | null>(null);

/** Todas as famílias que o catálogo REALMENTE tem — nunca um filtro vazio. */
const allGroups = computed(() => groupByFamily(props.types));

const visibleGroups = computed(() =>
    allGroups.value
        .filter((group) => familyFilter.value === null || group.family === familyFilter.value)
        .map((group) => ({
            ...group,
            categories: group.categories
                .map((category) => ({
                    ...category,
                    types: category.types.filter((type) => matchesSearch(type, search.value)),
                }))
                .filter((category) => category.types.length > 0),
        }))
        .filter((group) => group.categories.length > 0),
);

const resultCount = computed(() =>
    visibleGroups.value.reduce(
        (total, group) => total + group.categories.reduce((sum, category) => sum + category.types.length, 0),
        0,
    ),
);

const atCeiling = computed(() => props.max !== undefined && props.modelValue.length >= props.max);

function isSelected(value: string): boolean {
    return props.modelValue.includes(value);
}

/** Um card só fica inerte por causa do tecto, nunca por já estar escolhido. */
function isDisabled(value: string): boolean {
    return props.multiple && atCeiling.value && !isSelected(value);
}

function toggle(type: PresentedType): void {
    if (!props.multiple) {
        emit('update:modelValue', [type.value]);

        return;
    }

    if (isSelected(type.value)) {
        emit('update:modelValue', props.modelValue.filter((value) => value !== type.value));

        return;
    }

    if (atCeiling.value) {
        return;
    }

    emit('update:modelValue', [...props.modelValue, type.value]);
}
</script>

<template>
    <div class="space-y-3">
        <div class="relative">
            <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
            <input
                id="measure-search"
                v-model="search"
                type="search"
                class="min-h-11 w-full rounded-lg border border-border bg-background py-2 pr-3 pl-9 text-sm"
                placeholder="Pesquisar medidas ou estratégias…"
                aria-label="Pesquisar medidas ou estratégias"
            />
        </div>

        <!-- Um filtro por cada família que o catálogo tem, e nenhum a mais:
             uma família sem entradas nenhumas não ganha aqui um botão que
             não filtraria nada (§6 do pedido). -->
        <div v-if="allGroups.length > 1" class="flex flex-wrap gap-1.5" role="group" aria-label="Filtrar por natureza">
            <button
                type="button"
                class="min-h-9 rounded-full border px-3 py-1.5 text-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="familyFilter === null ? 'border-primary bg-primary/10 font-medium' : 'border-border text-muted-foreground hover:bg-muted/40'"
                :aria-pressed="familyFilter === null"
                @click="familyFilter = null"
            >
                Todas
            </button>
            <button
                v-for="group in allGroups"
                :key="group.family"
                type="button"
                class="inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="familyFilter === group.family ? 'border-primary bg-primary/10 font-medium' : 'border-border text-muted-foreground hover:bg-muted/40'"
                :aria-pressed="familyFilter === group.family"
                @click="familyFilter = familyFilter === group.family ? null : group.family"
            >
                <component :is="ICONS[group.icon]" class="size-3.5 shrink-0" aria-hidden="true" />
                {{ group.label }}
            </button>
        </div>

        <p v-if="multiple && max !== undefined" class="text-xs text-muted-foreground">
            {{ modelValue.length }} de {{ max }} escolhidas — cada uma terá acompanhamento independente.
        </p>

        <!-- Quantos resultados, dito em palavras e não só pela lista a
             encolher; e anunciado, para quem escreve sem ver o ecrã. -->
        <p class="sr-only" role="status" aria-live="polite">
            {{ resultCount }} {{ resultCount === 1 ? 'resultado' : 'resultados' }}
        </p>

        <div class="max-h-[26rem] space-y-4 overflow-y-auto pr-1">
            <section v-for="group in visibleGroups" :key="group.family" class="space-y-2">
                <h3 class="flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    <component :is="ICONS[group.icon]" class="size-4 shrink-0" aria-hidden="true" />
                    {{ group.label }}
                </h3>

                <div v-for="category in group.categories" :key="category.label" class="space-y-1.5">
                    <!-- O eixo pedagógico, que continua a existir: uma medida
                         é da família «suporte» E da categoria «Aprendizagem».
                         São coisas diferentes e mostram-se as duas (§13). -->
                    <p class="text-xs text-muted-foreground">{{ category.label }}</p>

                    <ul class="grid gap-1.5 sm:grid-cols-2">
                        <li v-for="type in category.types" :key="type.value">
                            <label
                                class="flex h-full min-h-11 cursor-pointer items-start gap-2 rounded-lg border p-2.5 text-sm transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-50"
                                :class="isSelected(type.value) ? 'border-primary bg-primary/5' : 'border-border hover:bg-muted/40'"
                            >
                                <input
                                    :type="multiple ? 'checkbox' : 'radio'"
                                    name="measure-picker"
                                    class="sr-only"
                                    :value="type.value"
                                    :checked="isSelected(type.value)"
                                    :disabled="isDisabled(type.value)"
                                    @change="toggle(type)"
                                />
                                <!-- O ✓ é a marca de selecionado; a cor da
                                     moldura é reforço, nunca a mensagem. -->
                                <span
                                    class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-sm border text-[0.625rem] leading-none"
                                    :class="isSelected(type.value) ? 'border-primary bg-primary text-primary-foreground' : 'border-border'"
                                    aria-hidden="true"
                                >
                                    <template v-if="isSelected(type.value)">✓</template>
                                </span>
                                <span class="min-w-0 flex-1 space-y-1">
                                    <span class="block leading-snug break-words">{{ type.label }}</span>
                                    <span v-if="type.levelLabel" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs" :class="type.toneClasses">
                                        {{ type.levelLabel }}<template v-if="type.isSuggestedLevel"> (possível)</template>
                                    </span>
                                </span>
                            </label>
                        </li>
                    </ul>
                </div>
            </section>

            <p v-if="visibleGroups.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                Nenhuma medida ou estratégia corresponde a esta pesquisa.
            </p>
        </div>
    </div>
</template>
