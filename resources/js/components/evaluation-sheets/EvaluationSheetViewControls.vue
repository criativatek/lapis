<script setup lang="ts">
/**
 * Controlos de visualização da pauta — SÓ APRESENTAÇÃO.
 *
 * Nenhum destes valores viaja para o servidor nem altera o que foi pedido:
 * esconder um grupo de colunas é uma decisão do ecrã, e o payload recebido
 * (vivo ou congelado) permanece intacto do início ao fim da visita.
 *
 * Partilhado pela pauta viva e pela pauta guardada, para que a mesma
 * fotografia possa ser lida das mesmas maneiras.
 */

withDefaults(
    defineProps<{
        /**
         * Se esta pauta tem alguma autoavaliação.
         *
         * SEM AUTOAVALIAÇÃO NÃO HÁ INTERRUPTOR. Um controlo que só pode
         * esconder o que não existe não é uma opção, é ruído — e a coluna vazia
         * que ele governaria seria pior ainda (§11).
         */
        selfAssessmentAvailable?: boolean;
    }>(),
    { selfAssessmentAvailable: false },
);

const showQuantitative = defineModel<boolean>('showQuantitative', { required: true });
const showDomainDetail = defineModel<boolean>('showDomainDetail', { required: true });
const showWarnings = defineModel<boolean>('showWarnings', { required: true });
const showSelfAssessment = defineModel<boolean>('showSelfAssessment', { required: true });
</script>

<template>
    <div class="flex flex-wrap items-center gap-4 rounded-lg border border-border bg-muted/20 px-4 py-2 text-sm">
        <span class="font-medium text-muted-foreground">Mostrar:</span>
        <label class="flex items-center gap-1.5">
            <input v-model="showQuantitative" type="checkbox" class="rounded border-border" />
            Valores quantitativos
        </label>
        <label class="flex items-center gap-1.5">
            <input v-model="showDomainDetail" type="checkbox" class="rounded border-border" />
            Detalhe por domínio
        </label>
        <label v-if="selfAssessmentAvailable" class="flex items-center gap-1.5">
            <input v-model="showSelfAssessment" type="checkbox" class="rounded border-border" />
            Autoavaliação
        </label>
        <label class="flex items-center gap-1.5">
            <input v-model="showWarnings" type="checkbox" class="rounded border-border" />
            Indicadores de cobertura
        </label>
    </div>
</template>
