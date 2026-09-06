<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { domainAppreciation, levelDetail } from '@/lib/appreciation';
import { pct } from '@/lib/results';
import type {
    EvaluationSheetDecisionScale,
    EvaluationSheetDomain,
    EvaluationSheetStudent,
} from '@/types';

/**
 * A apreciação de UM domínio, decidida.
 *
 * PEQUENO POR DESENHO. Leva o nome do aluno, o domínio, o quantitativo
 * calculado, a proposta do Lapispro e a lista fechada de menções desta escala —
 * e mais nada. Escolher é um clique, e o painel fecha-se a seguir.
 *
 * AS MENÇÕES SÃO AS DA ESCALA CONFIGURADA. Não há aqui nenhuma lista escrita à
 * mão: `decision.levels` é a mesma lista que Resultados e Classificações
 * recebem, e é ela que decide se as opções são «1…5», «NS/S/B/MB» ou outra
 * coisa qualquer (§7).
 *
 * «USAR A PROPOSTA DO LAPISPRO» NÃO É UMA MENÇÃO. É a ausência de decisão a ser
 * reposta: apaga o que o professor tinha escrito e devolve a célula à leitura
 * do sistema. Só aparece quando há decisão para apagar — «voltar» a um sítio de
 * onde ninguém saiu não é uma ação.
 *
 * O QUE ESTE PAINEL NÃO OFERECE é tão importante quanto o que oferece: não
 * deixa alterar o quantitativo e não deixa alterar a proposta. Nenhuma das duas
 * é do professor; a apreciação é (§3.3).
 */

const props = defineProps<{
    /** Null enquanto fechado. */
    student: EvaluationSheetStudent | null;
    domain: EvaluationSheetDomain | null;
    decision: EvaluationSheetDecisionScale;
    saving: boolean;
    /** A recusa do servidor, dita por palavras. */
    error: string | null;
}>();

const emit = defineEmits<{
    close: [];
    save: [scaleLevelId: number | null];
}>();

const isOpen = computed(() => props.student !== null && props.domain !== null);

/** A célula que está a ser decidida, tal como o modelo de leitura a traz. */
const cell = computed(() => {
    const student = props.student;
    const domain = props.domain;

    if (student === null || domain === null) {
        return null;
    }

    return student.domains.find((row) => row.domain_id === domain.domain_id) ?? null;
});

/**
 * O quantitativo CALCULADO. Está aqui como contexto e nunca como campo: é o que
 * o motor apurou, e uma decisão pedagógica não o corrige.
 */
const quantitative = computed(() => pct(cell.value?.normalized_value ?? null));

/**
 * A PROPOSTA, sempre com as duas metades — «3 — Suficiente». Aqui não se
 * esconde o código: este painel é onde se decide, e quem decide precisa de ver
 * a menção inteira independentemente da vista que estava ligada na grelha.
 */
const proposal = computed(
    () =>
        levelDetail(
            { code: cell.value?.scale_level_code, label: cell.value?.scale_level_label },
            true,
        ) ?? '—',
);

const decided = computed(
    () =>
        levelDetail(
            { code: cell.value?.decided_scale_level_code, label: cell.value?.decided_scale_level_label },
            true,
        ),
);

const chosenId = computed(() => cell.value?.decided_scale_level_id ?? null);

/** A leitura que está na grelha neste momento, para o painel dizer o mesmo. */
const current = computed(() => domainAppreciation(cell.value, true));
</script>

<template>
    <Dialog :open="isOpen" @update:open="(open: boolean) => !open && emit('close')">
        <DialogContent v-if="student && domain" class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ domain.name }} — {{ student.name }}</DialogTitle>
                <DialogDescription>
                    A apreciação deste domínio é sua. O Lapispro propõe; a decisão é
                    escrita ao lado da proposta e pode ser alterada sempre que quiser.
                </DialogDescription>
            </DialogHeader>

            <!-- O contexto, em três linhas e sem um único campo editável: o
                 quantitativo calculado, o que o Lapispro propõe, e o que está
                 decidido, se estiver. -->
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-md bg-muted/30 px-3 py-2 text-sm">
                <dt class="text-muted-foreground">Quantitativo calculado</dt>
                <dd class="tabular-nums">{{ quantitative }}</dd>
                <dt class="text-muted-foreground">Proposta do Lapispro</dt>
                <dd class="italic">{{ proposal }}</dd>
                <template v-if="decided">
                    <dt class="text-muted-foreground">Decisão atual</dt>
                    <dd class="font-semibold">{{ decided }}</dd>
                </template>
            </dl>

            <div v-if="decision.classifies_by_level" class="space-y-2">
                <p id="apreciacao-legenda" class="text-sm font-medium">Apreciação a atribuir</p>
                <div class="flex flex-wrap gap-2" role="group" aria-labelledby="apreciacao-legenda">
                    <button
                        v-for="level in decision.levels"
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

            <!-- Uma escala que é um intervalo não tem menções para escolher. Não
                 é um erro nem um ecrã por acabar: é a escala a não expressar
                 este tipo de juízo, e dizê-lo é melhor do que inventar bandas. -->
            <p v-else class="text-sm text-muted-foreground">
                A escala desta turma é um intervalo e não tem menções qualitativas,
                por isso não há apreciação por domínio a atribuir aqui.
            </p>

            <InputError :message="error ?? undefined" />

            <DialogFooter class="gap-2 sm:justify-between">
                <button
                    v-if="chosenId !== null"
                    type="button"
                    :disabled="saving"
                    class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40 disabled:opacity-60"
                    @click="emit('save', null)"
                >
                    Usar a proposta do Lapispro
                </button>
                <span v-else class="text-xs text-muted-foreground">
                    Está a ler-se {{ current.text }} — {{ current.origin === 'decided' ? 'decisão sua' : 'proposta do Lapispro' }}.
                </span>
                <button
                    type="button"
                    class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                    @click="emit('close')"
                >
                    Fechar
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
