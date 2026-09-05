<script setup lang="ts">
import { computed } from 'vue';
import CoverageWarning from '@/components/CoverageWarning.vue';
import { pct } from '@/lib/results';
import type { EvaluationSheetDomain, EvaluationSheetStudent } from '@/types';

/**
 * A grelha da Pauta de Avaliação — UM ÚNICO componente.
 *
 * A pauta viva e a pauta guardada usam exatamente este componente. É isso que
 * garante que uma fotografia se lê com as mesmas colunas, as mesmas cores e a
 * mesma estrutura do ecrã de onde foi tirada; duas cópias do markup seriam
 * duas coisas a divergir em silêncio na primeira correção feita só de um lado.
 *
 * NÃO CALCULA NADA. Recebe domínios e alunos já resolvidos pelo servidor —
 * vivos num caso, congelados no outro — e limita-se a mostrá-los. Os três
 * toggles SÓ ESCONDEM: nenhum deles toca nas props recebidas.
 */

const props = defineProps<{
    domains: EvaluationSheetDomain[];
    students: EvaluationSheetStudent[];
    showQuantitative: boolean;
    showDomainDetail: boolean;
    showWarnings: boolean;
}>();

const domainColumns = computed(() => props.domains.map((domain) => ({ id: domain.domain_id, name: domain.name })));

function studentDomain(student: EvaluationSheetStudent, domainId: number) {
    return student.domains.find((domain) => domain.domain_id === domainId);
}

/**
 * O QUE APARECE NA CÉLULA É O CÓDIGO DO NÍVEL, NÃO A MENÇÃO QUALITATIVA.
 * Numa pauta o nível é o número que o documento carrega — «3», não
 * «Suficiente» (SUP-2C774B). A menção continua a viajar, e vai para o `title`,
 * como o ecrã de Classificações já faz.
 *
 * O RECURSO AO RÓTULO É O QUE MANTÉM O HISTÓRICO LEGÍVEL: uma pauta guardada
 * antes desta correção não traz código nenhum, e tem de continuar a mostrar o
 * que estava no ecrã no dia em que foi guardada.
 */
function levelText(code: string | null | undefined, label: string | null): string | null {
    return code ?? label;
}

function levelTitle(code: string | null | undefined, label: string | null): string | undefined {
    if (code == null || label == null) {
        return undefined;
    }

    return `${code} — ${label}`;
}

/**
 * A proposta diz sempre o que é antes de dizer o que vale: a frase que a separa
 * de uma decisão vem primeiro, e a menção do nível só se junta quando existe.
 */
function proposalTitle(levelTitleText: string | undefined): string {
    const explanation = 'Proposta do Lapispro — ainda não decidida pelo professor.';

    return levelTitleText === undefined ? explanation : `${explanation} (${levelTitleText})`;
}

/**
 * O «Nível atribuído»: a decisão do professor quando existe, senão a proposta
 * do Lapispro com um estilo mais leve — nunca a mesma força visual, para que
 * uma proposta nunca se leia como uma decisão já tomada (§6).
 */
type AssignedLevel = { text: string; kind: 'decided' | 'proposed' | 'none'; title?: string };

function assignedLevel(student: EvaluationSheetStudent): AssignedLevel {
    const classification = student.classification;

    if (classification === null) {
        return { text: '—', kind: 'none' };
    }

    // Numa escala de intervalo não há nível nenhum a nomear: o valor escrito é
    // a resposta inteira, e é ele que passa no `??`.
    const finalText = levelText(classification.final_scale_level_code, classification.final_scale_level_label) ?? classification.final_value;

    if (finalText !== null) {
        return {
            text: finalText,
            kind: 'decided',
            title: levelTitle(classification.final_scale_level_code, classification.final_scale_level_label),
        };
    }

    const proposedText = levelText(classification.proposed_scale_level_code, classification.proposed_scale_level_label) ?? classification.proposed_value;

    if (proposedText !== null) {
        return {
            text: proposedText,
            kind: 'proposed',
            title: levelTitle(classification.proposed_scale_level_code, classification.proposed_scale_level_label),
        };
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
    <!-- `evaluation-sheet-grid` é um GANCHO DE IMPRESSÃO, não estilo. No papel
         não há scroll nem colunas fixas: o ecrã que imprime precisa de um nome
         estável para desligar o `max-h`/`overflow` e o `sticky` desta grelha,
         e um seletor pelas classes utilitárias partir-se-ia na primeira vez que
         alguém mudasse o `70vh`. -->
    <div class="evaluation-sheet-grid max-h-[70vh] overflow-auto rounded-lg border border-border">
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
                            v-for="domain in domains"
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
                        <template v-for="domain in domains" :key="`sub-${domain.domain_id}`">
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
                    v-for="(student, index) in students"
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
                        <template v-for="domain in domains" :key="`cell-${student.enrollment_id}-${domain.domain_id}`">
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
                                <span
                                        :class="{ 'text-muted-foreground': !levelText(studentDomain(student, domain.domain_id)?.scale_level_code, studentDomain(student, domain.domain_id)?.scale_level_label ?? null) }"
                                        :title="levelTitle(studentDomain(student, domain.domain_id)?.scale_level_code, studentDomain(student, domain.domain_id)?.scale_level_label ?? null)"
                                    >
                                        {{ levelText(studentDomain(student, domain.domain_id)?.scale_level_code, studentDomain(student, domain.domain_id)?.scale_level_label ?? null) ?? '—' }}
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
                        <span
                                :class="{ 'text-muted-foreground': !levelText(student.overall.scale_level_code, student.overall.scale_level_label) }"
                                :title="levelTitle(student.overall.scale_level_code, student.overall.scale_level_label)"
                            >
                                {{ levelText(student.overall.scale_level_code, student.overall.scale_level_label) ?? '—' }}
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
                            :title="assignedLevel(student).title"
                        >
                            {{ assignedLevel(student).text }}
                        </span>
                        <span
                            v-else-if="assignedLevel(student).kind === 'proposed'"
                            class="rounded px-2 py-0.5 text-muted-foreground italic"
                            :title="proposalTitle(assignedLevel(student).title)"
                        >
                            {{ assignedLevel(student).text }}
                        </span>
                        <span v-else class="text-muted-foreground">—</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
