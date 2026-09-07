<script setup lang="ts">
import { computed } from 'vue';
import DomainAppreciationPicker from '@/components/assessment/DomainAppreciationPicker.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { CONTINUOUS } from '@/lib/readings';
import { pct } from '@/lib/results';
import type { SynopticContinuousReading } from '@/lib/synopsis';

/**
 * A APRECIAÇÃO FINAL DE UM DOMÍNIO, decidida — a conclusão do ANO.
 *
 * O IRMÃO DESTE PAINEL É `EvaluationSheetDomainDecisionDialog`, e a única
 * diferença entre os dois é o QUE está a ser decidido: aquele decide a leitura
 * de um período, este decide a do ano. A escolha em si — as menções da escala e
 * o «voltar à proposta» — é literalmente o mesmo componente.
 *
 * O CONTEXTO É O QUE MUDA, e é o que faz a decisão ser informada: em vez do
 * quantitativo de um período, mostra-se a MÉDIA FINAL e as parcelas de que ela
 * é feita — o resultado formal de cada unidade. Quem vai concluir o ano precisa
 * de ver o trajecto, não só o número.
 *
 * NADA AQUI ALTERA UM NÚMERO. A média final continua a ser a média final, a
 * proposta continua a ser a proposta, e os resultados de cada unidade não são
 * recalculados. O que se escreve é a leitura que o professor assume (§3.3).
 */

const props = defineProps<{
    /** Null enquanto fechado. */
    studentName: string | null;
    domainName: string | null;
    reading: SynopticContinuousReading | null;
    decision: {
        classifies_by_level: boolean;
        levels: { id: number; code: string; label: string }[];
    };
    saving: boolean;
    error: string | null;
}>();

const emit = defineEmits<{
    close: [];
    save: [scaleLevelId: number | null];
}>();

const isOpen = computed(() => props.studentName !== null && props.domainName !== null);

const chosenId = computed(() => props.reading?.decision?.final?.scale_level_id ?? null);

/** A proposta que sai da média — sempre com as duas metades, «3 — Suficiente». */
const proposal = computed(() => {
    const level = props.reading?.level ?? null;

    return level === null ? '—' : `${level.code} — ${level.label}`;
});

const decided = computed(() => {
    const level = props.reading?.decision?.final ?? null;

    return level === null ? null : `${level.code} — ${level.label}`;
});

const currentSummary = computed(
    () => `Está a ler-se ${decided.value ?? proposal.value} — ${decided.value === null ? 'proposta do Lapispro' : 'decisão sua'}.`,
);
</script>

<template>
    <Dialog :open="isOpen" @update:open="(open: boolean) => !open && emit('close')">
        <DialogContent v-if="studentName && domainName" class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ domainName }} — {{ studentName }}</DialogTitle>
                <DialogDescription>
                    A conclusão do ano neste domínio é sua. O Lapispro propõe a partir da
                    média dos resultados formais; a decisão fica ao lado da proposta e pode
                    ser alterada sempre que quiser.
                </DialogDescription>
            </DialogHeader>

            <!-- O CONTEXTO, sem um único campo editável: a média final, as
                 parcelas de que ela é feita, a proposta que dela sai, e o que
                 está decidido — se estiver. -->
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-md bg-muted/30 px-3 py-2 text-sm">
                <dt class="text-muted-foreground">{{ CONTINUOUS }} final</dt>
                <dd class="tabular-nums">{{ pct(reading?.normalized_value ?? null) }}</dd>
                <template v-for="unit in reading?.units ?? []" :key="unit.period_id">
                    <dt class="pl-3 text-xs text-muted-foreground">{{ unit.label }}</dt>
                    <dd class="text-xs tabular-nums">{{ pct(unit.normalized_value) }}</dd>
                </template>
                <dt class="text-muted-foreground">Proposta do Lapispro</dt>
                <dd>{{ proposal }}</dd>
                <template v-if="decided">
                    <dt class="text-muted-foreground">Decisão atual</dt>
                    <dd class="font-semibold">{{ decided }}</dd>
                </template>
            </dl>

            <DomainAppreciationPicker
                :levels="decision.levels"
                :classifies-by-level="decision.classifies_by_level"
                :chosen-id="chosenId"
                :saving="saving"
                :error="error"
                :current-summary="currentSummary"
                @save="(level: number | null) => emit('save', level)"
            >
                <template #actions>
                    <button
                        type="button"
                        class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                        @click="emit('close')"
                    >
                        Fechar
                    </button>
                </template>
            </DomainAppreciationPicker>
        </DialogContent>
    </Dialog>
</template>
