<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { pct } from '@/lib/results';
import type {
    EvaluationSheetDecisionScale,
    EvaluationSheetDomain,
    EvaluationSheetSelfAssessment,
    EvaluationSheetStudent,
} from '@/types';

/**
 * Onde o professor ATRIBUI a classificação, a partir da própria pauta.
 *
 * DUAS COISAS DIFERENTES, LADO A LADO E NUNCA MISTURADAS: a PROPOSTA do
 * Lapispro, que o professor não altera nem pode alterar, e a DECISÃO, que é
 * dele e só dele (§3.3). O painel mostra a primeira como contexto e recolhe a
 * segunda; nenhum campo aqui escreve na proposta.
 *
 * COMPACTO POR DESENHO. Não é uma segunda pauta: leva o que é preciso para
 * decidir — o nome, a proposta, os domínios, o que o aluno disse de si próprio
 * e o que já está atribuído — e mais nada. Quem quiser a turma inteira tem-na
 * atrás, que é de onde este painel foi aberto.
 *
 * NÃO GUARDA NADA. Emite o que o professor escolheu; quem escreve é o ecrã,
 * pelo caminho canónico (`classifications.decide`).
 */

const props = defineProps<{
    /** Null enquanto fechado. O aluno cuja decisão está a ser tomada. */
    student: EvaluationSheetStudent | null;
    decision: EvaluationSheetDecisionScale;
    domains: EvaluationSheetDomain[];
    saving: boolean;
    /** A recusa do servidor, dita por palavras. Nunca uma célula a fingir que guardou. */
    error: string | null;
}>();

const emit = defineEmits<{
    close: [];
    save: [payload: { final_scale_level_id: number | null; final_value: string | null }];
    useProposal: [];
}>();

const chosenLevelId = ref<number | null>(null);
const chosenValue = ref<string>('');

/**
 * ABRE NO QUE JÁ ESTÁ ATRIBUÍDO, não na proposta.
 *
 * Alterar uma decisão começa na decisão que existe — é isso que o professor
 * está a rever. Onde ainda não há decisão nenhuma o campo abre vazio: uma
 * proposta pré-selecionada seria o sistema a decidir por omissão, que é
 * exatamente o que §3.3 proíbe.
 */
watch(
    () => props.student,
    (student) => {
        chosenLevelId.value = student?.classification?.final_scale_level_id ?? null;
        chosenValue.value = student?.classification?.final_value ?? '';
    },
    { immediate: true },
);

const isOpen = computed(() => props.student !== null);

/** «3 — Suficiente» quando há menção, «16» quando a escala é um intervalo. */
function readable(code: string | null | undefined, label: string | null | undefined, value: string | null): string {
    const head = code ?? value;

    if (head === null || head === undefined) {
        return '—';
    }

    return label ? `${head} — ${label}` : head;
}

const proposal = computed(() => {
    const classification = props.student?.classification;

    if (!classification) {
        return '—';
    }

    return readable(
        classification.proposed_scale_level_code,
        classification.proposed_scale_level_label,
        classification.proposed_value,
    );
});

const assigned = computed(() => {
    const classification = props.student?.classification;

    if (!classification) {
        return null;
    }

    if (classification.final_scale_level_id === null && classification.final_value === null) {
        return null;
    }

    return readable(
        classification.final_scale_level_code,
        classification.final_scale_level_label,
        classification.final_value,
    );
});

/**
 * A autoavaliação dita por inteiro — de quem é, o código e a menção.
 *
 * Um «4» solto ao lado do nível atribuído seria lido como uma segunda nota; a
 * frase começa por dizer que é o ALUNO a falar de si próprio.
 */
function selfAssessmentTitle(level: EvaluationSheetSelfAssessment | null | undefined, subject?: string): string | undefined {
    if (!level) {
        return undefined;
    }

    const what = subject === undefined ? 'Autoavaliação do aluno' : `Autoavaliação do aluno — ${subject}`;

    return `${what}: ${level.code} — ${level.label}`;
}

/** O global do aluno: o valor na escala quando existe, senão a percentagem. */
const overall = computed(() => {
    const student = props.student;

    if (!student) {
        return '—';
    }

    return student.overall.scale_value ?? pct(student.overall.normalized_value);
});

/** Os domínios do perfil, com o que este aluno tem em cada um. */
const domainRows = computed(() =>
    props.domains.map((domain) => {
        const row = props.student?.domains.find((candidate) => candidate.domain_id === domain.domain_id);

        return {
            id: domain.domain_id,
            name: domain.name,
            color: domain.color,
            quantitative: pct(row?.normalized_value ?? null),
            level: row?.scale_level_code ?? row?.scale_level_label ?? null,
            levelTitle:
                row?.scale_level_code && row.scale_level_label
                    ? `${row.scale_level_code} — ${row.scale_level_label}`
                    : undefined,
            warning: row?.has_coverage_warning === true,
            said: row?.self_assessment ?? null,
        };
    }),
);

/** Se este aluno se pronunciou de todo — globalmente ou sobre algum domínio. */
const hasSelfAssessment = computed(
    () =>
        (props.student?.self_assessment ?? null) !== null ||
        (props.student?.domains.some((domain) => (domain.self_assessment ?? null) !== null) ?? false),
);

/** Vazio não é uma decisão: guardar exige que alguma coisa tenha sido escolhida. */
const canSave = computed(() =>
    props.decision.classifies_by_level ? chosenLevelId.value !== null : chosenValue.value.trim() !== '',
);

function save(): void {
    if (!canSave.value || props.saving) {
        return;
    }

    emit(
        'save',
        props.decision.classifies_by_level
            ? { final_scale_level_id: chosenLevelId.value, final_value: null }
            : { final_scale_level_id: null, final_value: chosenValue.value.trim() },
    );
}

function onOpenChange(open: boolean): void {
    if (!open) {
        emit('close');
    }
}
</script>

<template>
    <Dialog :open="isOpen" @update:open="onOpenChange">
        <DialogContent v-if="student" class="max-h-[90vh] gap-3 overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>
                    {{ assigned === null ? 'Atribuir' : 'Alterar' }} — {{ student.name }}
                </DialogTitle>
                <DialogDescription>
                    O Lapispro propõe; a classificação é sua. A proposta fica como está, aconteça o que
                    acontecer a esta decisão.
                </DialogDescription>
            </DialogHeader>

            <!-- O QUE SE PRECISA DE VER PARA DECIDIR, e por esta ordem: o que o
                 sistema propôs, o que já está atribuído, e as evidências. -->
            <div class="grid gap-2 sm:grid-cols-2">
                <div class="rounded-md border border-border bg-muted/20 px-3 py-2">
                    <p class="text-xs text-muted-foreground">Proposta do Lapispro</p>
                    <p class="text-sm font-medium">{{ proposal }}</p>
                </div>
                <div class="rounded-md border border-border bg-muted/20 px-3 py-2">
                    <p class="text-xs text-muted-foreground">{{ decision.label }}</p>
                    <p class="text-sm font-medium">{{ assigned ?? 'Ainda não atribuído' }}</p>
                </div>
            </div>

            <div class="rounded-md border border-border">
                <div class="flex items-center justify-between border-b border-border px-3 py-1.5 text-xs">
                    <span class="font-medium">Global</span>
                    <span class="flex items-center gap-3">
                        <!-- A perceção do aluno ao lado da evidência, que é o
                             ponto de a mostrar aqui: comparar. Rotulada, para
                             que nunca se leia como mais um resultado. -->
                        <span v-if="hasSelfAssessment" class="text-muted-foreground">
                            Autoavaliação:
                            <span class="font-medium" :title="selfAssessmentTitle(student.self_assessment)">
                                {{ student.self_assessment?.code ?? '—' }}
                            </span>
                        </span>
                        <span class="tabular-nums">{{ overall }}</span>
                    </span>
                </div>
                <ul class="divide-y divide-border">
                    <li
                        v-for="row in domainRows"
                        :key="row.id"
                        class="flex items-center justify-between gap-2 px-3 py-1.5 text-xs"
                    >
                        <span class="flex min-w-0 items-center gap-1.5">
                            <!-- Cor = identidade do domínio, nunca desempenho.
                                 E nunca a única informação: o nome está lá. -->
                            <span
                                class="size-2.5 shrink-0 rounded-full"
                                :style="{ backgroundColor: row.color }"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{ row.name }}</span>
                            <span v-if="row.warning" class="shrink-0 text-amber-600" title="Cobertura parcial ou elementos em falta.">⚠</span>
                        </span>
                        <span class="flex shrink-0 items-center gap-3 tabular-nums">
                            <span
                                v-if="hasSelfAssessment"
                                class="min-w-6 text-right text-muted-foreground"
                                :title="selfAssessmentTitle(row.said, row.name)"
                                :aria-label="selfAssessmentTitle(row.said, row.name)"
                            >{{ row.said ? `A${row.said.code}` : '' }}</span>
                            <span class="text-muted-foreground">{{ row.quantitative }}</span>
                            <span class="min-w-6 text-right font-medium" :title="row.levelTitle">{{ row.level ?? '—' }}</span>
                        </span>
                    </li>
                </ul>
                <p v-if="hasSelfAssessment" class="border-t border-border px-3 py-1.5 text-[11px] text-muted-foreground">
                    «A» é o que o aluno disse de si próprio. É informação de apoio — nunca determina a
                    classificação.
                </p>
            </div>

            <!-- ONDE A DECISÃO É TOMADA. Um seletor fechado na escala de níveis,
                 o intervalo da própria escala quando é isso que ela é. -->
            <div class="space-y-1">
                <label :for="`decisao-${student.enrollment_id}`" class="text-sm font-medium">
                    {{ decision.label }}
                </label>
                <select
                    v-if="decision.classifies_by_level"
                    :id="`decisao-${student.enrollment_id}`"
                    v-model="chosenLevelId"
                    class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                >
                    <option :value="null">— escolher —</option>
                    <option v-for="level in decision.levels" :key="level.id" :value="level.id">
                        {{ level.code }} — {{ level.label }}
                    </option>
                </select>
                <input
                    v-else
                    :id="`decisao-${student.enrollment_id}`"
                    v-model="chosenValue"
                    type="number"
                    step="0.001"
                    inputmode="decimal"
                    :min="decision.min_value ?? undefined"
                    :max="decision.max_value ?? undefined"
                    class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm tabular-nums"
                />
                <p v-if="!decision.classifies_by_level && decision.min_value" class="text-xs text-muted-foreground">
                    Entre {{ decision.min_value }} e {{ decision.max_value }}.
                </p>
                <InputError :message="error ?? undefined" />
            </div>

            <DialogFooter class="gap-2 sm:justify-between">
                <!-- «Usar proposta» é uma DECISÃO como outra qualquer: adotar a
                     proposta é um ato explícito, escrito como tal, e só existe
                     onde há proposta por adotar. -->
                <button
                    v-if="student.can_use_proposal"
                    type="button"
                    class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40 disabled:opacity-60"
                    :disabled="saving"
                    @click="emit('useProposal')"
                >
                    Usar proposta
                </button>
                <span v-else />

                <span class="flex gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                        @click="emit('close')"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground disabled:opacity-60"
                        :disabled="!canSave || saving"
                        @click="save"
                    >
                        {{ saving ? 'A guardar…' : 'Guardar decisão' }}
                    </button>
                </span>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
