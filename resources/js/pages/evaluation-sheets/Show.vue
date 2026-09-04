<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Table2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import CoverageWarning from '@/components/CoverageWarning.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { pct } from '@/lib/results';
import type { EvaluationSheet, EvaluationSheetPeriod, EvaluationSheetStudent } from '@/types';

/**
 * Pautas de Avaliação — UMA ÚNICA VISTA.
 *
 * Abre com tudo visível: quantitativo, apreciação qualitativa por domínio, e
 * classificação sugerida vs. decidida. Os três toggles abaixo SÓ ESCONDEM —
 * nunca recalculam nada nem alteram o payload recebido do servidor, que
 * permanece intacto em `props.sheet` do início ao fim da visita.
 */

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string; academic_year: string; has_profile: boolean };
    periods: EvaluationSheetPeriod[];
    sheet: EvaluationSheet | null;
}>();

const selectedPeriod = computed<EvaluationSheetPeriod | null>(
    () => props.periods.find((period) => period.selected) ?? null,
);

function selectPeriod(ulid: string): void {
    router.get(`/classes/${props.schoolClass.ulid}/pauta-avaliacao/${ulid}`, {}, { preserveScroll: true });
}

// ------------------------------------------------------------- apresentação
//
// SÓ CLIENT-SIDE. Nenhum destes refs viaja para o servidor nem altera o que é
// pedido — ocultar um grupo é puramente visual (§ briefing, regra central).

const showQuantitative = ref(true);
const showDomainDetail = ref(true);
const showWarnings = ref(true);

const domainColumns = computed(() => (props.sheet?.domains ?? []).map((domain) => ({ id: domain.domain_id, name: domain.name })));

function studentDomain(student: EvaluationSheetStudent, domainId: number) {
    return student.domains.find((domain) => domain.domain_id === domainId);
}

/**
 * O «Nível atribuído»: a decisão do professor quando existe, senão a proposta
 * do Lapispro com um estilo mais leve — nunca a mesma força visual, para que
 * uma proposta nunca se leia como uma decisão já tomada (§6).
 */
type AssignedLevel = { text: string; kind: 'decided' | 'proposed' | 'none' };

function assignedLevel(student: EvaluationSheetStudent): AssignedLevel {
    const classification = student.classification;

    if (classification === null) {
        return { text: '—', kind: 'none' };
    }

    const finalText = classification.final_scale_level_label ?? classification.final_value;

    if (finalText !== null) {
        return { text: finalText, kind: 'decided' };
    }

    const proposedText = classification.proposed_scale_level_label ?? classification.proposed_value;

    if (proposedText !== null) {
        return { text: proposedText, kind: 'proposed' };
    }

    return { text: '—', kind: 'none' };
}

/** Fundo muito suave na cor do domínio — identidade visual, nunca desempenho. */
function domainHeaderStyle(color: string): Record<string, string> {
    return { backgroundColor: `${color}66` };
}

function domainCellStyle(color: string): Record<string, string> {
    return { backgroundColor: `${color}26` };
}
</script>

<template>
    <Head :title="`Pauta de Avaliação — ${schoolClass.label}`" />

    <div class="space-y-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <Heading
                    :title="`Pauta de Avaliação — ${schoolClass.label}`"
                    :description="
                        selectedPeriod
                            ? `${schoolClass.subject} · ${selectedPeriod.kind_label} selecionado: ${selectedPeriod.label}`
                            : schoolClass.subject
                    "
                />
                <Link :href="`/classes/${schoolClass.ulid}`" class="text-sm text-muted-foreground hover:underline">
                    ← Voltar à turma
                </Link>
            </div>

            <div v-if="periods.length" class="flex flex-wrap gap-1">
                <button
                    v-for="period in periods"
                    :key="period.ulid"
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm"
                    :class="period.selected ? 'border-primary bg-primary text-primary-foreground' : 'border-border hover:bg-muted/40'"
                    :title="period.kind_label"
                    @click="selectPeriod(period.ulid)"
                >
                    {{ period.label }}
                </button>
            </div>
        </div>

        <!-- Controlos de visualização: só apresentação, nunca alteram os dados
             recebidos do servidor. -->
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
            <label class="flex items-center gap-1.5">
                <input v-model="showWarnings" type="checkbox" class="rounded border-border" />
                Indicadores de cobertura
            </label>
        </div>

        <p v-if="!schoolClass.has_profile" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Esta turma não tem perfil de avaliação associado, por isso não há pauta a mostrar.
        </p>

        <EmptyState
            v-else-if="sheet === null || sheet.students.length === 0"
            title="Sem alunos ou sem resultados neste período."
            :icon="Table2"
        />

        <div v-else class="max-h-[70vh] overflow-auto rounded-lg border border-border">
            <!-- `min-w-full`, não `w-full`: com muitos domínios a tabela é mais
                 larga do que o contentor e as colunas têm de manter a largura
                 natural (senão o cabeçalho da coluna fixa é espremido e cortado).
                 O contentor é que rola. -->
            <table class="min-w-full border-collapse text-sm">
                <thead>
                    <!-- Fundos OPACOS em tudo o que é sticky. Um `bg-muted/50`
                         deixa passar o que desliza por baixo: numa turma de 20-30
                         alunos o cabeçalho fica ilegível sobre as linhas, e a
                         coluna fixa mistura-se com a apreciação que passa sob ela. -->
                    <tr class="sticky top-0 z-20 bg-muted text-left text-xs">
                        <th rowspan="2" class="sticky left-0 z-30 border-b border-border bg-muted px-3 py-2 align-bottom font-medium shadow-[8px_0_8px_-6px_rgba(0,0,0,0.10)]">
                            Aluno
                        </th>
                        <template v-if="showDomainDetail">
                            <th
                                v-for="domain in sheet.domains"
                                :key="domain.domain_id"
                                :colspan="showQuantitative ? 2 : 1"
                                class="border-b border-l border-border px-3 py-1.5 text-center font-semibold"
                                :style="domainHeaderStyle(domain.color)"
                            >
                                {{ domain.name }}
                            </th>
                        </template>
                        <th
                            :colspan="showQuantitative ? 2 : 1"
                            class="border-b border-l-2 border-border bg-muted/70 px-3 py-1.5 text-center font-semibold"
                        >
                            Global
                        </th>
                        <!-- Fixa à direita pela mesma razão que «Aluno» é fixa à
                             esquerda: é a coluna da DECISÃO. Numa turma com cinco
                             domínios a tabela é mais larga do que o ecrã, e a
                             coluna que não pode desaparecer no scroll é
                             precisamente esta (§11 — legibilidade é requisito). -->
                        <th
                            rowspan="2"
                            class="sticky right-0 z-30 border-b border-l-2 border-border bg-muted px-3 py-2 text-center align-bottom font-medium shadow-[-8px_0_8px_-6px_rgba(0,0,0,0.10)]"
                        >
                            <!-- Quebra deliberada em duas linhas: a coluna é
                                 estreita e «Nível atribuído» com nowrap transbordava
                                 da célula fixa, aparecendo cortado a meio da palavra. -->
                            <span class="block">Nível</span>
                            <span class="block">atribuído</span>
                        </th>
                    </tr>
                    <tr class="sticky z-20 bg-muted text-left text-[11px] text-muted-foreground" style="top: 2.25rem">
                        <template v-if="showDomainDetail">
                            <template v-for="domain in sheet.domains" :key="`sub-${domain.domain_id}`">
                                <th v-if="showQuantitative" class="border-b border-l border-border px-2 py-1 text-center font-normal" :style="domainCellStyle(domain.color)">
                                    Quant.
                                </th>
                                <th class="border-b border-border px-2 py-1 text-center font-normal" :class="showQuantitative ? '' : 'border-l'" :style="domainCellStyle(domain.color)">
                                    Apreciação
                                </th>
                            </template>
                        </template>
                        <th v-if="showQuantitative" class="border-b border-l-2 border-border bg-muted/40 px-2 py-1 text-center font-normal">
                            Quant.
                        </th>
                        <th class="border-b border-border bg-muted/40 px-2 py-1 text-center font-normal" :class="showQuantitative ? 'border-l' : 'border-l-2'">
                            Apreciação
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(student, index) in sheet.students"
                        :key="student.enrollment_id"
                        :class="index % 2 === 1 ? 'bg-muted/10' : ''"
                        class="hover:bg-muted/20"
                    >
                        <!-- Sem a risca alternada nas duas colunas fixas: a risca é
                             `bg-muted/10` e ganharia ao `bg-background`, deixando a
                             célula translúcida — as linhas passariam por baixo dela.
                             A risca continua a ler-se em todas as colunas que rolam. -->
                        <td class="sticky left-0 z-10 border-b border-border bg-background px-3 py-2 whitespace-nowrap shadow-[8px_0_8px_-6px_rgba(0,0,0,0.10)]">
                            <span class="text-muted-foreground">{{ student.class_number ?? '—' }}</span>
                            <span class="ml-1.5 font-medium">{{ student.name }}</span>
                        </td>

                        <template v-if="showDomainDetail">
                            <template v-for="domain in sheet.domains" :key="`cell-${student.enrollment_id}-${domain.domain_id}`">
                                <td
                                    v-if="showQuantitative"
                                    class="border-b border-l border-border px-2 py-2 text-center tabular-nums"
                                    :style="domainCellStyle(domain.color)"
                                >
                                    <span :class="{ 'text-muted-foreground': studentDomain(student, domain.domain_id)?.normalized_value == null }">
                                        {{ pct(studentDomain(student, domain.domain_id)?.normalized_value ?? null) }}
                                    </span>
                                    <CoverageWarning
                                        v-if="showWarnings && studentDomain(student, domain.domain_id)?.has_coverage_warning"
                                        :coverage="studentDomain(student, domain.domain_id)!.coverage"
                                        :has-value="(studentDomain(student, domain.domain_id)?.normalized_value ?? null) !== null"
                                        :domains="domainColumns"
                                    />
                                </td>
                                <td
                                    class="border-b border-border px-2 py-2 text-center"
                                    :class="showQuantitative ? '' : 'border-l'"
                                    :style="domainCellStyle(domain.color)"
                                >
                                    <span :class="{ 'text-muted-foreground': !studentDomain(student, domain.domain_id)?.scale_level_label }">
                                        {{ studentDomain(student, domain.domain_id)?.scale_level_label ?? '—' }}
                                    </span>
                                    <CoverageWarning
                                        v-if="showWarnings && !showQuantitative && studentDomain(student, domain.domain_id)?.has_coverage_warning"
                                        :coverage="studentDomain(student, domain.domain_id)!.coverage"
                                        :has-value="(studentDomain(student, domain.domain_id)?.normalized_value ?? null) !== null"
                                        :domains="domainColumns"
                                    />
                                </td>
                            </template>
                        </template>

                        <td v-if="showQuantitative" class="border-b border-l-2 border-border bg-muted/20 px-2 py-2 text-center font-medium tabular-nums">
                            <span :class="{ 'text-muted-foreground': student.overall.scale_value == null && student.overall.normalized_value == null }">
                                {{ student.overall.scale_value ?? pct(student.overall.normalized_value) }}
                            </span>
                            <CoverageWarning
                                v-if="showWarnings && student.overall.has_coverage_warning"
                                :coverage="student.coverage"
                                :has-value="student.overall.normalized_value !== null"
                                :domains="domainColumns"
                                scope="overall"
                            />
                        </td>
                        <td
                            class="border-b border-border bg-muted/20 px-2 py-2 text-center font-medium"
                            :class="showQuantitative ? '' : 'border-l-2'"
                        >
                            <span :class="{ 'text-muted-foreground': !student.overall.scale_level_label }">
                                {{ student.overall.scale_level_label ?? '—' }}
                            </span>
                            <CoverageWarning
                                v-if="showWarnings && !showQuantitative && student.overall.has_coverage_warning"
                                :coverage="student.coverage"
                                :has-value="student.overall.normalized_value !== null"
                                :domains="domainColumns"
                                scope="overall"
                            />
                        </td>

                        <td
                            class="sticky right-0 z-10 border-b border-l-2 border-border bg-background px-3 py-2 text-center whitespace-nowrap shadow-[-8px_0_8px_-6px_rgba(0,0,0,0.10)]"
                        >
                            <span
                                v-if="assignedLevel(student).kind === 'decided'"
                                class="rounded bg-primary/10 px-2 py-0.5 font-bold text-primary"
                            >
                                {{ assignedLevel(student).text }}
                            </span>
                            <span
                                v-else-if="assignedLevel(student).kind === 'proposed'"
                                class="rounded px-2 py-0.5 text-muted-foreground italic"
                                title="Proposta do Lapispro — ainda não decidida pelo professor."
                            >
                                {{ assignedLevel(student).text }}
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-xs text-muted-foreground">
            "—" significa sem elementos, nunca zero. O ícone de aviso assinala cobertura parcial ou a ausência de
            elementos avaliados — passe o rato ou o foco por cima para ver o detalhe. Um nível em itálico é a
            <strong>proposta</strong> do Lapispro, ainda não decidida; um nível a negrito é a
            <strong>decisão</strong> do professor.
        </p>
    </div>
</template>
