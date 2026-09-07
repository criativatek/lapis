<script setup lang="ts">
import InputError from '@/components/InputError.vue';

/**
 * A LISTA FECHADA DE MENÇÕES DE UMA ESCALA, e a acção de voltar à proposta.
 *
 * EXTRAÍDO PORQUE PASSOU A HAVER DOIS SÍTIOS ONDE SE DECIDE UM DOMÍNIO: a
 * Pauta, que decide a leitura de UM período, e o Quadro Síntese, que decide a
 * conclusão do ANO. As duas oferecem exactamente a mesma escolha — as menções
 * da escala configurada, e nada mais — e duas cópias divergiriam na primeira
 * correcção feita só de um lado.
 *
 * AS MENÇÕES SÃO AS DA ESCALA CONFIGURADA. Não há aqui nenhuma lista escrita à
 * mão: `levels` é a mesma que Resultados e Classificações recebem, e é ela que
 * decide se as opções são «1…5», «NS/S/B/MB» ou outra coisa qualquer (§7).
 *
 * «USAR A PROPOSTA DO LAPISPRO» NÃO É UMA MENÇÃO. É a ausência de decisão a ser
 * reposta: apaga o que o professor tinha escrito e devolve a célula à leitura
 * do sistema. Só aparece quando há decisão para apagar — «voltar» a um sítio de
 * onde ninguém saiu não é uma acção.
 *
 * UMA ESCALA QUE É UM INTERVALO não tem menções para escolher. Não é um erro
 * nem um ecrã por acabar: é a escala a não expressar este tipo de juízo, e
 * dizê-lo é melhor do que inventar bandas.
 */

defineProps<{
    /** As menções da escala configurada, na ordem dela. */
    levels: { id: number; code: string; label: string }[];
    /** Falso numa escala de intervalo, que não tem menções a atribuir. */
    classifiesByLevel: boolean;
    /** A decisão que está escrita, para a marcar; null quando vigora a proposta. */
    chosenId: number | null;
    saving: boolean;
    /** A recusa do servidor, dita por palavras. */
    error: string | null;
    /** O que se lê agora, para o rodapé o dizer quando não há nada a limpar. */
    currentSummary: string;
}>();

const emit = defineEmits<{ save: [scaleLevelId: number | null] }>();
</script>

<template>
    <div>
        <div v-if="classifiesByLevel" class="space-y-2">
            <p id="apreciacao-legenda" class="text-sm font-medium">Apreciação a atribuir</p>
            <div class="flex flex-wrap gap-2" role="group" aria-labelledby="apreciacao-legenda">
                <button
                    v-for="level in levels"
                    :key="level.id"
                    type="button"
                    :disabled="saving"
                    class="rounded-md border px-3 py-1.5 text-sm disabled:opacity-60"
                    :class="chosenId === level.id
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-border hover:bg-muted/40'"
                    :aria-pressed="chosenId === level.id"
                    @click="emit('save', level.id)"
                >
                    <span class="font-medium">{{ level.code }}</span>
                    <span class="ml-1.5 opacity-80">{{ level.label }}</span>
                </button>
            </div>
        </div>

        <p v-else class="text-sm text-muted-foreground">
            A escala desta turma é um intervalo e não tem menções qualitativas,
            por isso não há apreciação por domínio a atribuir aqui.
        </p>

        <InputError :message="error ?? undefined" />

        <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
            <button
                v-if="chosenId !== null"
                type="button"
                :disabled="saving"
                class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40 disabled:opacity-60"
                @click="emit('save', null)"
            >
                Usar a proposta do Lapispro
            </button>
            <span v-else class="text-xs text-muted-foreground">{{ currentSummary }}</span>
            <slot name="actions" />
        </div>
    </div>
</template>
