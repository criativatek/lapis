<script setup lang="ts">
import { CircleAlert, Info, ShieldCheck, Users } from '@lucide/vue';
import { ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import KpiCard from '@/components/infographic/KpiCard.vue';
import SectionHeading from '@/components/infographic/SectionHeading.vue';
import { percent, studentsCount } from '@/lib/assessmentComparison';
import { COVERAGE_WORD, countOf } from '@/lib/classStrengths';
import type { ClassStrengths, StrengthsPoint, StrengthsRow } from '@/lib/classStrengths';
import { card } from '@/lib/surfaces';

/**
 * Pontos fortes e dificuldades da turma, num momento (Pro).
 *
 * LÊ-SE, NÃO SE CALCULA. Médias, contagens, destaques e a linha entre positivo e negativo chegam
 * decididos do servidor, com a escala e os critérios que o momento tinha. A página diz sempre
 * «k de n alunos», não destaca um domínio quando há empate, e não promete mais do que os números:
 * «abaixo do nível positivo» descreve uma nota — não é um diagnóstico — e nenhuma causa, previsão
 * ou medida é sugerida.
 */

const props = defineProps<{ strengths: ClassStrengths }>();

const emit = defineEmits<{ select: [momentKey: string] }>();

const momentKey = ref(props.strengths.selection.moment ?? '');

watch(
    () => props.strengths.selection,
    (selection) => {
        momentKey.value = selection.moment ?? '';
    },
);

function choose(): void {
    if (momentKey.value !== '' && momentKey.value !== props.strengths.selection.moment) {
        emit('select', momentKey.value);
    }
}

function optionText(point: StrengthsPoint): string {
    return `${point.label} — ${point.basis.kind === 'frozen' ? point.basis.label : 'hoje'} (${point.students_with_result}/${point.students_total})`;
}

const COVERAGE_CLASS: Record<StrengthsRow['coverage']['state'], string> = {
    none: 'text-rose-700 dark:text-rose-400',
    few: 'text-amber-700 dark:text-amber-500',
    incomplete: 'text-amber-700 dark:text-amber-500',
    complete: 'text-muted-foreground',
};

function belowText(row: StrengthsRow): string {
    return row.below_positive === null ? '—' : `${countOf(row.below_positive.count, row.below_positive.of)} (${percent(row.below_positive.share)})`;
}

function mark(row: StrengthsRow): string | null {
    const ranking = props.strengths.ranking;

    if (ranking.highest?.ulid === row.ulid) {
        return 'Média mais alta';
    }

    return ranking.lowest?.ulid === row.ulid ? 'Média mais baixa' : null;
}
</script>

<template>
    <section aria-labelledby="pontos-fortes-titulo" class="space-y-6" data-test="strengths">
        <div>
            <h2 id="pontos-fortes-titulo" class="text-lg font-semibold tracking-tight">Pontos fortes e dificuldades</h2>
            <p class="mt-0.5 text-sm text-muted-foreground">
                Os domínios da turma num momento, com a escala e os critérios desse momento. Não precisa de comparar dois momentos.
            </p>
        </div>

        <EmptyState
            v-if="strengths.state !== 'ready' || !strengths.moment"
            title="Ainda não há resultados para analisar."
            :description="strengths.reason ?? undefined"
            :icon="Users"
        />

        <template v-else>
            <!-- ================================================== o momento -->
            <div class="space-y-3">
                <label class="block max-w-md space-y-1 text-sm">
                    <span class="font-medium">Momento</span>
                    <select
                        v-model="momentKey"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        data-test="strengths-select"
                        @change="choose"
                    >
                        <option v-for="option in strengths.options" :key="option.key" :value="option.key">{{ optionText(option) }}</option>
                    </select>
                </label>

                <div class="space-y-2 text-sm" data-test="strengths-limits">
                    <p class="text-muted-foreground">
                        <span class="font-medium text-foreground">{{ strengths.moment.label }}:</span> {{ strengths.moment.basis.label }} ·
                        {{ countOf(strengths.moment.students_with_result, strengths.moment.students_total) }} com resultado
                        <span v-if="strengths.moment.partial_coverage > 0" class="text-amber-700 dark:text-amber-500">
                            · {{ strengths.moment.partial_coverage }} com cobertura parcial
                        </span>
                    </p>
                    <p v-if="strengths.basis_note" class="flex gap-2 rounded-lg bg-muted/50 p-3">
                        <Info class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <span>{{ strengths.basis_note }}</span>
                    </p>
                    <p
                        v-for="caution in strengths.cautions"
                        :key="caution.code"
                        class="flex gap-2 rounded-lg border border-amber-200/70 bg-amber-50/70 p-3 dark:border-amber-900/40 dark:bg-amber-950/20"
                    >
                        <CircleAlert class="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-500" aria-hidden="true" />
                        <span>{{ caution.text }}</span>
                    </p>
                    <p
                        v-if="!strengths.negative_line.known && strengths.negative_line.text"
                        class="flex gap-2 rounded-lg border border-amber-200/70 bg-amber-50/70 p-3 dark:border-amber-900/40 dark:bg-amber-950/20"
                        data-test="strengths-no-line"
                    >
                        <CircleAlert class="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-500" aria-hidden="true" />
                        <span>{{ strengths.negative_line.text }}</span>
                    </p>
                    <p v-else-if="strengths.negative_line.negative_levels.length" class="text-muted-foreground" data-test="strengths-line">
                        Abaixo do nível positivo, nesta escala:
                        <span class="font-medium text-foreground">{{ strengths.negative_line.negative_levels.join(', ') }}</span>.
                    </p>
                </div>
            </div>

            <!-- ================================================== resultado global -->
            <div v-if="strengths.result" class="grid gap-3 sm:grid-cols-2" data-test="strengths-result">
                <KpiCard
                    tone="sky"
                    :icon="Users"
                    label="Resultado global da turma"
                    :value="percent(strengths.result.mean)"
                    :context="`${countOf(strengths.result.students.with_value, strengths.result.students.of)} com resultado`"
                />
                <KpiCard
                    tone="plain"
                    :icon="ShieldCheck"
                    label="Resultado global abaixo do nível positivo"
                    :value="strengths.result.below_positive ? `${strengths.result.below_positive.count}` : '—'"
                    :context="strengths.result.below_positive ? `de ${studentsCount(strengths.result.below_positive.of)} com resultado` : 'Sem linha entre positivo e negativo neste momento'"
                />
            </div>

            <!-- ================================================== domínios -->
            <section aria-labelledby="forte-dominios" class="space-y-4">
                <SectionHeading
                    id="forte-dominios"
                    index="01"
                    title="Por domínio"
                    description="Média, alunos abaixo do nível positivo e cobertura de cada domínio neste momento."
                />

                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm" data-test="strengths-domains">
                        <thead class="bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 font-medium">Domínio</th>
                                <th class="px-3 py-2 text-right font-medium">Média</th>
                                <th class="px-3 py-2 font-medium">Alunos com valor</th>
                                <th class="px-3 py-2 font-medium">Abaixo do nível positivo</th>
                                <th class="px-3 py-2 font-medium">Cobertura</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="row in strengths.domains" :key="row.ulid ?? 'x'" :data-test="`strengths-domain-${row.coverage.state}`">
                                <td class="px-3 py-2 font-medium">
                                    {{ row.name }}
                                    <span
                                        v-if="mark(row)"
                                        class="ml-2 rounded-full bg-muted px-2 py-0.5 text-[11px] font-normal text-muted-foreground"
                                        data-test="strengths-mark"
                                    >
                                        {{ mark(row) }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ percent(row.mean) }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ countOf(row.students.with_value, row.students.of) }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ belowText(row) }}</td>
                                <td class="px-3 py-2 text-xs" :class="COVERAGE_CLASS[row.coverage.state]">
                                    {{ COVERAGE_WORD[row.coverage.state] }}
                                    <template v-if="row.coverage.partial > 0"> · parcial em {{ row.coverage.partial }}</template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="strengths.ranking.highest_tie.length" class="text-sm text-muted-foreground" data-test="strengths-tie-high">
                    Empate na média mais alta: {{ strengths.ranking.highest_tie.join(' e ') }}. Nenhum é destacado.
                </p>
                <p v-if="strengths.ranking.lowest_tie.length" class="text-sm text-muted-foreground" data-test="strengths-tie-low">
                    Empate na média mais baixa: {{ strengths.ranking.lowest_tie.join(' e ') }}. Nenhum é destacado.
                </p>
                <p v-if="strengths.ranking.state === 'all_equal'" class="text-sm text-muted-foreground">
                    Todos os domínios com resultados têm a mesma média: nenhum é destacado.
                </p>
                <p v-if="strengths.ranking.state === 'insufficient'" class="text-sm text-muted-foreground" data-test="strengths-ranking-insufficient">
                    Só há média em menos de dois domínios: não há mais alta nem mais baixa para destacar.
                </p>
            </section>

            <!-- ================================================== destaques -->
            <section aria-labelledby="forte-destaques" class="space-y-4">
                <SectionHeading
                    id="forte-destaques"
                    index="02"
                    title="Pontos fortes e resultados a observar"
                    description="Descrições dos números mostrados, cada uma com a sua evidência. Não indicam causas nem propõem medidas."
                />

                <div class="grid gap-4 lg:grid-cols-2">
                    <div :class="[card('mint'), 'space-y-2 p-4 text-sm']" data-test="strengths-list">
                        <h3 class="font-semibold">Pontos fortes</h3>
                        <ul v-if="strengths.highlights.strengths.length" class="space-y-1.5">
                            <li v-for="item in strengths.highlights.strengths" :key="item.key + (item.evidence.domain ?? '')">{{ item.text }}</li>
                        </ul>
                        <p v-else class="text-muted-foreground">Nada a destacar com os dados deste momento.</p>
                    </div>
                    <div :class="[card('amber'), 'space-y-2 p-4 text-sm']" data-test="attention-list">
                        <h3 class="font-semibold">Resultados a observar</h3>
                        <ul v-if="strengths.highlights.attention.length" class="space-y-1.5">
                            <li v-for="item in strengths.highlights.attention" :key="item.key + (item.evidence.domain ?? '')">{{ item.text }}</li>
                        </ul>
                        <p v-else class="text-muted-foreground">Nada a destacar com os dados deste momento.</p>
                    </div>
                </div>
            </section>

            <!-- ================================================== alunos -->
            <section aria-labelledby="forte-alunos" class="space-y-4">
                <SectionHeading
                    id="forte-alunos"
                    index="03"
                    title="Alunos abaixo do nível positivo em mais de um domínio"
                    description="Lista nominal, só para os professores da turma. Um resultado abaixo do nível positivo descreve uma nota; não é um diagnóstico."
                />

                <p v-if="!strengths.students_below.known" class="text-sm text-muted-foreground" data-test="strengths-students-unknown">
                    Sem linha entre positivo e negativo neste momento: não se contam alunos abaixo do nível positivo.
                </p>
                <template v-else>
                    <p class="text-sm" data-test="strengths-students-summary">
                        Entre {{ studentsCount(strengths.students_below.of) }} com valor em pelo menos um domínio:
                        <span class="tabular-nums">{{ strengths.students_below.none }}</span> em nenhum domínio abaixo do nível positivo,
                        <span class="tabular-nums">{{ strengths.students_below.one }}</span> num domínio,
                        <span class="font-medium tabular-nums">{{ strengths.students_below.two_or_more }}</span> em dois ou mais.
                    </p>

                    <ul v-if="strengths.students_below.students.length" class="divide-y divide-border rounded-lg border border-border" data-test="strengths-students">
                        <li v-for="student in strengths.students_below.students" :key="student.enrollment_ulid" class="space-y-1 px-4 py-3 text-sm">
                            <p class="font-medium">
                                <span v-if="student.class_number !== null" class="mr-2 tabular-nums text-muted-foreground">{{ student.class_number }}</span>{{ student.name }}
                                <span class="ml-2 text-xs font-normal text-muted-foreground">
                                    {{ student.domains.length }} de {{ student.domains_with_value }} domínios com valor
                                </span>
                            </p>
                            <ul class="flex flex-wrap gap-2 text-xs">
                                <li v-for="domain in student.domains" :key="domain.ulid" class="rounded-full bg-muted px-2.5 py-1">
                                    {{ domain.name }}: <span class="tabular-nums">{{ percent(domain.value) }}</span>
                                    <span class="text-muted-foreground"> · {{ domain.band.label ?? domain.band.code }}</span>
                                </li>
                            </ul>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">Nenhum aluno está abaixo do nível positivo em dois ou mais domínios neste momento.</p>
                </template>
            </section>

            <!-- ================================================== explicações -->
            <div class="divide-y divide-border rounded-lg border border-border">
                <details v-for="item in strengths.explanations" :key="item.key" class="px-4 py-3 text-sm" :data-test="`strengths-how-${item.key}`">
                    <summary class="cursor-pointer font-medium">{{ item.title }}</summary>
                    <p class="mt-2 leading-relaxed text-muted-foreground">{{ item.text }}</p>
                </details>
            </div>
        </template>
    </section>
</template>
