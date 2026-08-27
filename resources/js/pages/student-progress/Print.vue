<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatPoints, pct } from '@/lib/chartTheme';

/**
 * Acompanhamento do Aluno — print/PDF output.
 *
 * ONE PRINT INFRASTRUCTURE, composed from `document.sections` alone — never
 * an `if (plan === 'pro')` anywhere in this file. Every `v-if="has('...')"`
 * below asks the SAME question the server already answered through
 * `BuildStudentPrintDocument`: is this key part of the document? A future
 * capability plugged into that class changes what is printed with no edit
 * here (§9, §15 of the print brief).
 *
 * A DOCUMENT, NOT A SCREEN (§6, §17). No sidebar, no nav, no app header, no
 * dropdowns, no tooltips — `app.ts` renders this page with no layout at all.
 * The reading is always the canonical one the panel itself defaults to; a
 * printed page is a snapshot for a meeting, not a place to toggle a view.
 *
 * THE TWO ON-SCREEN ACTIONS ARE NOT PART OF THE DOCUMENT. «Voltar ao aluno»,
 * «Imprimir / Guardar PDF» and the browser-settings hint live inside
 * `.doc-actions`, which `@media print` removes outright — so they exist for
 * the person at the screen and never for the paper. The hint is only a hint:
 * a page cannot switch off the browser's own headers and footers, and
 * pretending otherwise would be a promise this code cannot keep.
 *
 * FORMATTING IS NOT REINVENTED HERE. `pct()` / `formatPoints()` are the same
 * functions the panel (Show.vue) already uses — imported, never re-written —
 * and the date formatter below is the exact same `Intl.DateTimeFormat`
 * idiom Show.vue already uses inline (dates have no shared helper in this
 * codebase to import from).
 *
 * NEVER AN UN-ACCEPTED AI SUGGESTION (§11). This page's props carry no `ai`,
 * `aiSuggestion` or `aiPurposeSuggestions` key at all — the controller never
 * sends them — so there is nothing here that could print a proposal nobody
 * accepted. What appears under «Estratégias e Medidas» is exactly the same
 * `interventions.rows` the panel reads: recorded decisions, never proposals.
 */

type Level = {
    scale_level_id?: number;
    code: string | null;
    label: string | null;
    sequence?: number;
    is_negative?: boolean | null;
};

type Evolution = { direction: 'up' | 'down' | 'flat'; points: string } | null;

type Coverage = 'complete' | 'partial' | 'none';

type Moment = {
    key: string;
    kind: 'period' | 'interim';
    label: string;
    date: string | null;
    period_label?: string;
    value: string | null;
    reading: 'accumulated' | 'period';
    coverage: Coverage;
    before_enrolment: boolean;
};

type DomainRow = {
    domain_id: number;
    name: string;
    accumulated_average: string | null;
    mention: Level | null;
    evolution: Evolution;
    coverage: Coverage;
};

type ClassificationRow = {
    period_id: number;
    period_label: string;
    is_decided: boolean;
    assigned: Level | null;
    assigned_value: string | null;
    proposal: { label?: string | null; value?: string | null } | null;
    differs_from_proposal: boolean;
};

type SelfAssessmentRow = {
    period_id: number;
    period_label: string;
    self_assessment: Level | null;
    assigned: Level | null;
    comparison: { difference: number; direction: 'above' | 'below' | 'same' } | null;
};

type RecordRow = {
    ulid: string;
    kind_label: string;
    occurred_at: string;
    description: string;
    domain: string | null;
    severity: string | null;
    homework_status: string | null;
    participation_level: string | null;
};

type FactualNote = { key: string; sentence: string };

type RecentInstrument = {
    ulid: string;
    title: string;
    type: string | null;
    applied_on: string;
    result: string | null;
    scale_label: string | null;
    evolution: Evolution;
};

type InterventionRow = {
    ulid: string;
    title: string;
    effectiveness: string | null;
    last_followup_on: string | null;
    followup_count: number;
    status: string;
    started_on: string;
    domain: string | null;
    is_individual: boolean;
    purpose_label: string | null;
    frequency: string | null;
    tracking_indicator: string | null;
};

/** Estado 360º — the 8 approved dimensions, unchanged from the panel. */
type Dimension = { key: string; label: string; state: string; state_label: string; detail: string };

type ProPayload = {
    estado360: { dimensions: Dimension[] };
    analyticalAlerts: FactualNote[];
    positiveSignals: FactualNote[];
    potentialities: {
        narrative: string | null;
        strengths: { domain_id: number; name: string; detail: string }[];
        progressing: { domain_id: number; name: string; detail: string }[];
        next_step: string | null;
    };
    whatChanged: { items: FactualNote[]; empty_sentence: string | null };
};

type DocumentSection = { key: string; label: string; capability: string };

const props = defineProps<{
    student: {
        ulid: string;
        name: string;
        class_number: number | null;
        is_current: boolean;
        status_label: string;
        status_reason: string | null;
    };
    schoolClass: {
        ulid: string;
        label: string;
        subject: string;
        academic_year: string;
    };
    reading: { kind: 'accumulated' | 'period'; label: string; caption: string };
    selectedPeriod: { id: number; label: string } | null;
    headline: {
        value: string | null;
        band: Level | null;
        coverage: Coverage;
        classification: { status?: string; final?: Level | null; final_value?: string | null } | null;
        self_assessment: Level | null;
    };
    moments: Moment[];
    classifications: ClassificationRow[];
    selfAssessments: SelfAssessmentRow[];
    domains: {
        rows: DomainRow[];
        highlights: {
            highest: { name: string; value: string } | null;
            lowest: { name: string; value: string } | null;
            largest_rise: { name: string; value: string } | null;
            largest_fall: { name: string; value: string } | null;
        };
    };
    sinceLast: {
        from_label: string;
        to_label: string;
        from: string | null;
        to: string | null;
        classification_from: Level | null;
        classification_to: Level | null;
    } | null;
    classComparison: { student: string; class: string; students_with_result: number; difference: string } | null;
    records: { total: number; rows: RecordRow[] };
    interventions: { total: number; rows: InterventionRow[] };
    recentInstruments: RecentInstrument[];
    narrative: string | null;
    factualAlerts: FactualNote[];
    strengths: FactualNote[];
    pro?: ProPayload;
    document: { title: string; sections: DocumentSection[] };
    generatedAt: string;
}>();

const sectionKeys = computed(() => new Set(props.document.sections.map((section) => section.key)));

function has(key: string): boolean {
    return sectionKeys.value.has(key);
}

// Back to the panel this document was opened from — the same two ulids the
// print route itself is addressed by, so it always returns to the right
// student rather than to a picker.
const panelHref = computed(
    () => `/classes/${props.schoolClass.ulid}/evolucao/${props.student.ulid}`,
);

function printDocument(): void {
    window.print();
}

// -------------------------------------------------------------- formatação

// The exact same Intl idiom Show.vue already uses inline — there is no
// shared date formatter in this codebase to import instead of duplicating.
const dateFormatter = new Intl.DateTimeFormat('pt-PT', { day: 'numeric', month: 'long', year: 'numeric' });

function longDate(value: string): string {
    return dateFormatter.format(new Date(`${value}T00:00:00`));
}

function arrow(direction: 'up' | 'down' | 'flat' | 'above' | 'below' | 'same'): string {
    return direction === 'up' || direction === 'above' ? '↑' : direction === 'down' || direction === 'below' ? '↓' : '→';
}

const COVERAGE_LABEL: Record<Coverage, string> = {
    complete: 'Completa',
    partial: 'Cobertura parcial',
    none: 'Sem elementos avaliados',
};

const assignedLabel = computed(() => {
    const classification = props.headline.classification;
    const decided = classification?.status === 'confirmed' || classification?.status === 'published';

    return decided ? (classification?.final?.code ?? classification?.final_value ?? null) : null;
});

const withValueMoments = computed(() => props.moments.filter((moment) => moment.value !== null));

const currentStrengths = computed(() => props.strengths.filter((row) => row.key === 'highest_domain'));
const progressingStrengths = computed(() => props.strengths.filter((row) => row.key === 'largest_rise'));
const otherStrengths = computed(() => props.strengths.filter((row) => !['highest_domain', 'largest_rise'].includes(row.key)));

// Same neutral/attention/positive reading the panel already uses for these
// same state keys — never a print-only interpretation (§9, §10 of the brief).
const ATTENTION_STATES = new Set([
    'abaixo_da_turma', 'descida', 'consistente_a_descer', 'a_reforcar', 'a_acompanhar',
    'revisao_pendente', 'foco_na_consolidacao',
]);
const POSITIVE_STATES = new Set([
    'acima_da_turma', 'subida', 'consistente_a_subir', 'consistente', 'positivo',
    'identificados', 'em_progressao', 'consolidado_com_margem', 'em_curso',
]);

function dimensionClass(state: string): string {
    if (ATTENTION_STATES.has(state)) {
        return 'dim-attention';
    }

    if (POSITIVE_STATES.has(state)) {
        return 'dim-positive';
    }

    return 'dim-neutral';
}
</script>

<template>
    <Head :title="`${document.title} — ${student.name}`" />

    <div class="doc-page">
        <!-- Screen only — removed outright by @media print below. -->
        <div class="doc-actions">
            <div class="doc-actions-row">
                <Link :href="panelHref" class="doc-action">← Voltar ao aluno</Link>
                <button type="button" class="doc-action doc-action-primary" @click="printDocument">
                    🖨 Imprimir / Guardar PDF
                </button>
            </div>
            <p class="doc-actions-hint">
                Para um documento limpo, desative «Cabeçalhos e rodapés» nas opções de impressão do navegador.
            </p>
        </div>

        <header class="doc-header">
            <p class="doc-eyebrow">Lapispro · Acompanhamento do Aluno</p>
            <h1 class="doc-title">{{ document.title }}</h1>
            <h2 class="doc-student">{{ student.name }}</h2>
            <p class="doc-meta">
                <template v-if="student.class_number">N.º {{ student.class_number }} · </template>{{ schoolClass.label }} ·
                {{ schoolClass.subject }} · {{ schoolClass.academic_year }}
                <template v-if="selectedPeriod"> · {{ selectedPeriod.label }}</template>
            </p>
            <p v-if="!student.is_current" class="doc-meta">
                Já não integra a turma<template v-if="student.status_reason"> — {{ student.status_reason }}</template>
            </p>
            <p class="doc-meta">Gerado em {{ longDate(generatedAt) }}</p>
        </header>

        <div class="doc-content">
            <!-- A secção «Identificação» foi removida de propósito: o
                 cabeçalho acima já diz nome, n.º, turma, disciplina, ano
                 letivo e período, e repeti-los num cartão logo abaixo era
                 gastar meia página a dizer duas vezes a mesma coisa. O
                 documento começa onde a informação começa. -->

            <!-- ---------------------------------------- situação atual -->
            <section v-if="has('current_situation')" class="doc-section">
                <h2 class="doc-heading">Situação atual</h2>
                <dl class="doc-grid">
                    <div>
                        <dt>{{ reading.label }}</dt>
                        <dd>{{ headline.value === null ? '—' : pct(headline.value) }}
                            <span v-if="headline.band?.label" class="doc-sub">({{ headline.band.label }})</span>
                        </dd>
                    </div>
                    <div>
                        <dt>Classificação atribuída</dt>
                        <dd>{{ assignedLabel ?? 'Sem classificação atribuída' }}</dd>
                    </div>
                    <div>
                        <dt>Autoavaliação</dt>
                        <dd>{{ headline.self_assessment?.code ?? 'Sem autoavaliação' }}</dd>
                    </div>
                    <div>
                        <dt>Cobertura do cálculo</dt>
                        <dd>{{ COVERAGE_LABEL[headline.coverage] }}</dd>
                    </div>
                </dl>
                <p v-if="narrative" class="doc-narrative">{{ narrative }}</p>
            </section>

            <!-- ---------------------------------------- resultados por domínio -->
            <section v-if="has('domains')" class="doc-section">
                <h2 class="doc-heading">Resultados por domínio</h2>
                <table class="doc-table">
                    <thead>
                        <tr><th>Domínio</th><th>Resultado</th><th>Variação</th><th>Cobertura</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in domains.rows" :key="row.domain_id">
                            <td>{{ row.name }}</td>
                            <td>
                                <template v-if="row.accumulated_average !== null">
                                    {{ pct(row.accumulated_average) }}
                                    <span v-if="row.mention?.label" class="doc-sub"> · {{ row.mention.label }}</span>
                                </template>
                                <template v-else>Sem elementos avaliados</template>
                            </td>
                            <td>
                                <template v-if="row.evolution">
                                    {{ arrow(row.evolution.direction) }} {{ formatPoints(row.evolution.points) }} p.p.
                                </template>
                                <template v-else>Não comparável</template>
                            </td>
                            <td>{{ COVERAGE_LABEL[row.coverage] }}</td>
                        </tr>
                    </tbody>
                </table>
                <ul class="doc-highlights">
                    <li v-if="domains.highlights.highest">Resultado mais elevado: {{ domains.highlights.highest.name }} ({{ pct(domains.highlights.highest.value) }})</li>
                    <li v-if="domains.highlights.lowest">Resultado mais baixo: {{ domains.highlights.lowest.name }} ({{ pct(domains.highlights.lowest.value) }})</li>
                    <li v-if="domains.highlights.largest_rise">Maior subida: {{ domains.highlights.largest_rise.name }} ({{ formatPoints(domains.highlights.largest_rise.value) }} p.p.)</li>
                    <li v-if="domains.highlights.largest_fall">Maior descida: {{ domains.highlights.largest_fall.name }} ({{ formatPoints(domains.highlights.largest_fall.value) }} p.p.)</li>
                </ul>
            </section>

            <!-- ---------------------------------------- como evoluiu (histórico factual) -->
            <!-- A TABELA, NUNCA O GRÁFICO (§18 do brief). Um canvas Chart.js
                 depende de um resize disparado pelo browser antes de imprimir
                 e de nenhuma dependência nova ser trazida só para isto — uma
                 tabela é uma leitura tão clara quanto e imprime de forma
                 previsível em qualquer motor. -->
            <section v-if="has('evolution')" class="doc-section">
                <h2 class="doc-heading">Como evoluiu</h2>
                <table class="doc-table">
                    <thead>
                        <tr><th>Momento</th><th>Tipo</th><th>{{ reading.label }}</th><th>Cobertura</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="moment in moments" :key="moment.key">
                            <td>{{ moment.label }}</td>
                            <td>{{ moment.kind === 'interim' ? 'Avaliação intercalar' : 'Fim do período' }}</td>
                            <td>{{ moment.value === null ? '—' : pct(moment.value) }}</td>
                            <td>{{ moment.before_enrolment ? 'Anterior à matrícula' : COVERAGE_LABEL[moment.coverage] }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="withValueMoments.length < 2" class="doc-sub">
                    Ainda não existem dois momentos com resultado apurado.
                </p>

                <div v-if="sinceLast" class="doc-since">
                    <p>
                        {{ sinceLast.from_label }} → {{ sinceLast.to_label }}:
                        <template v-if="sinceLast.from !== null && sinceLast.to !== null">
                            {{ reading.label }} de {{ pct(sinceLast.from) }} para {{ pct(sinceLast.to) }}.
                        </template>
                        <template v-if="sinceLast.classification_from?.code && sinceLast.classification_to?.code">
                            Classificação atribuída de {{ sinceLast.classification_from.code }} para {{ sinceLast.classification_to.code }}.
                        </template>
                    </p>
                </div>

                <p v-if="classComparison" class="doc-since">
                    Em relação à turma: aluno {{ pct(classComparison.student) }} · turma {{ pct(classComparison.class) }}
                    ({{ formatPoints(classComparison.difference) }} p.p., média de {{ classComparison.students_with_result }} alunos com resultado apurado).
                </p>
            </section>

            <!-- ---------------------------------------- últimas avaliações -->
            <section v-if="has('recent_assessments')" class="doc-section">
                <h2 class="doc-heading">Últimas avaliações</h2>
                <table class="doc-table">
                    <thead>
                        <tr><th>Data</th><th>Título</th><th>Tipo</th><th>Resultado</th><th>Evolução</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="instrument in recentInstruments" :key="instrument.ulid">
                            <td>{{ longDate(instrument.applied_on) }}</td>
                            <td>{{ instrument.title }}</td>
                            <td>{{ instrument.type ?? '—' }}</td>
                            <td>
                                <template v-if="instrument.result !== null">
                                    {{ pct(instrument.result) }}
                                    <span v-if="instrument.scale_label" class="doc-sub"> · {{ instrument.scale_label }}</span>
                                </template>
                                <template v-else>Sem resultado individual</template>
                            </td>
                            <td>
                                <template v-if="instrument.evolution">
                                    {{ arrow(instrument.evolution.direction) }} {{ formatPoints(instrument.evolution.points) }} p.p.
                                </template>
                                <template v-else>Sem comparação</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- ---------------------------------------- autoavaliação -->
            <section v-if="has('self_assessments')" class="doc-section">
                <h2 class="doc-heading">Autoavaliação</h2>
                <table class="doc-table">
                    <thead>
                        <tr><th>Período</th><th>Autoavaliação</th><th>Atribuída</th><th>Comparação</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in selfAssessments" :key="row.period_id">
                            <td>{{ row.period_label }}</td>
                            <td>{{ row.self_assessment?.code ?? '—' }}</td>
                            <td>{{ row.assigned?.code ?? '—' }}</td>
                            <td>
                                <template v-if="row.comparison && row.comparison.direction !== 'same'">
                                    {{ arrow(row.comparison.direction) }} {{ row.comparison.direction === 'above' ? 'acima' : 'abaixo' }}
                                </template>
                                <template v-else>—</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- ---------------------------------------- classificações atribuídas -->
            <section v-if="has('academic_records')" class="doc-section">
                <h2 class="doc-heading">Classificações atribuídas</h2>
                <table class="doc-table">
                    <thead>
                        <tr><th>Período</th><th>Classificação</th><th>Proposta</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in classifications" :key="row.period_id">
                            <td>{{ row.period_label }}</td>
                            <td>
                                <template v-if="row.is_decided">{{ row.assigned?.code ?? row.assigned_value }}</template>
                                <template v-else>Sem classificação atribuída</template>
                            </td>
                            <td>
                                <template v-if="row.proposal?.label && row.differs_from_proposal">{{ row.proposal.label }}</template>
                                <template v-else>—</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- ---------------------------------------- registos -->
            <section v-if="has('records')" class="doc-section">
                <h2 class="doc-heading">Registos</h2>
                <p class="doc-sub">{{ records.total }} {{ records.total === 1 ? 'registo' : 'registos' }} sobre este aluno.</p>
                <ol class="doc-list">
                    <li v-for="row in records.rows" :key="row.ulid">
                        <span class="doc-sub">{{ longDate(row.occurred_at) }}</span> —
                        <strong>{{ row.kind_label }}</strong>
                        <template v-if="row.domain"> · {{ row.domain }}</template>
                        <template v-if="row.severity"> · {{ row.severity }}</template>
                        <template v-if="row.homework_status"> · {{ row.homework_status }}</template>
                        <template v-if="row.participation_level"> · {{ row.participation_level }}</template>
                        <br /><span>{{ row.description }}</span>
                    </li>
                </ol>
            </section>

            <!-- ---------------------------------------- atenção (factual) -->
            <section v-if="has('attention_factual')" class="doc-section">
                <h2 class="doc-heading">Atenção</h2>
                <ul class="doc-list">
                    <li v-for="alert in factualAlerts" :key="alert.key">{{ alert.sentence }}</li>
                </ul>
            </section>

            <!-- ---------------------------------------- pontos fortes (factual) -->
            <section v-if="has('strengths_factual')" class="doc-section">
                <h2 class="doc-heading">Pontos fortes</h2>
                <div v-if="currentStrengths.length > 0">
                    <h3 class="doc-subheading">Ponto forte atual</h3>
                    <ul class="doc-list"><li v-for="row in currentStrengths" :key="row.key">{{ row.sentence }}</li></ul>
                </div>
                <div v-if="progressingStrengths.length > 0">
                    <h3 class="doc-subheading">Domínio em progressão</h3>
                    <ul class="doc-list"><li v-for="row in progressingStrengths" :key="row.key">{{ row.sentence }}</li></ul>
                </div>
                <div v-if="otherStrengths.length > 0">
                    <h3 class="doc-subheading">Outros sinais positivos</h3>
                    <ul class="doc-list"><li v-for="row in otherStrengths" :key="row.key">{{ row.sentence }}</li></ul>
                </div>
            </section>

            <!-- ---------------------------------------- estratégias e medidas -->
            <!-- Só o que o professor decidiu e registou — nunca uma sugestão
                 de IA por aceitar (§11 do brief: esta página não recebe sequer
                 as props `ai`/`aiSuggestion`/`aiPurposeSuggestions`). -->
            <section v-if="has('strategies')" class="doc-section">
                <h2 class="doc-heading">Estratégias e Medidas</h2>
                <ol class="doc-list">
                    <li v-for="row in interventions.rows" :key="row.ulid">
                        <strong>{{ row.title }}</strong>
                        <template v-if="row.purpose_label"> · {{ row.purpose_label }}</template>
                        <template v-if="row.domain"> · {{ row.domain }}</template>
                        · {{ row.status }} · {{ row.is_individual ? 'dirigida a este aluno' : 'dirigida à turma' }}
                        <br />
                        <span class="doc-sub">
                            Início: {{ longDate(row.started_on) }}
                            <template v-if="row.frequency"> · {{ row.frequency }}</template>
                            <template v-if="row.tracking_indicator"> · Indicador: {{ row.tracking_indicator }}</template>
                            <template v-if="row.last_followup_on"> · Última revisão: {{ longDate(row.last_followup_on) }} ({{ row.followup_count }})</template>
                        </span>
                        <!-- Só quando já FACTUALMENTE registada (§ factual sections). -->
                        <span v-if="row.effectiveness" class="doc-sub"><br />Eficácia observada: {{ row.effectiveness }}</span>
                    </li>
                </ol>
            </section>

            <!-- ================================ ANALÍTICO (Pro) ================================ -->

            <section v-if="has('attention_analytical') && pro" class="doc-section doc-analytical">
                <h2 class="doc-heading">Atenção — leitura analítica</h2>
                <ul class="doc-list">
                    <li v-for="alert in pro.analyticalAlerts" :key="alert.key">{{ alert.sentence }}</li>
                </ul>
            </section>

            <section v-if="has('positive_signals') && pro" class="doc-section doc-analytical">
                <h2 class="doc-heading">Sinais positivos</h2>
                <ul class="doc-list">
                    <li v-for="signal in pro.positiveSignals" :key="signal.key">{{ signal.sentence }}</li>
                </ul>
            </section>

            <section v-if="has('estado360') && pro" class="doc-section doc-analytical">
                <h2 class="doc-heading">Estado 360º</h2>
                <dl class="doc-grid doc-dimensions">
                    <div v-for="dimension in pro.estado360.dimensions" :key="dimension.key" :class="dimensionClass(dimension.state)">
                        <dt>{{ dimension.label }}</dt>
                        <dd>{{ dimension.state_label }}</dd>
                        <dd class="doc-sub">{{ dimension.detail }}</dd>
                    </div>
                </dl>
            </section>

            <section v-if="has('what_changed') && pro" class="doc-section doc-analytical">
                <h2 class="doc-heading">O que mudou</h2>
                <ul v-if="pro.whatChanged.items.length > 0" class="doc-list">
                    <li v-for="item in pro.whatChanged.items" :key="item.key">{{ item.sentence }}</li>
                </ul>
                <p v-else class="doc-sub">{{ pro.whatChanged.empty_sentence }}</p>
            </section>

            <section v-if="has('potentialities') && pro" class="doc-section doc-analytical">
                <h2 class="doc-heading">Potencialidades</h2>
                <dl class="doc-grid">
                    <div>
                        <dt>Pontos fortes</dt>
                        <dd v-if="pro.potentialities.strengths.length > 0">
                            <span v-for="item in pro.potentialities.strengths" :key="item.domain_id">{{ item.detail }}<br /></span>
                        </dd>
                        <dd v-else class="doc-sub">Sem ponto forte atual isolado.</dd>
                    </div>
                    <div>
                        <dt>Em progressão</dt>
                        <dd v-if="pro.potentialities.progressing.length > 0">
                            <span v-for="item in pro.potentialities.progressing" :key="item.domain_id">{{ item.detail }}<br /></span>
                        </dd>
                        <dd v-else class="doc-sub">Sem evolução recente isolada.</dd>
                    </div>
                    <div>
                        <dt>Próximo passo</dt>
                        <dd>{{ pro.potentialities.next_step ?? 'Sem elementos suficientes para formular um próximo passo.' }}</dd>
                    </div>
                </dl>
                <p v-if="pro.potentialities.narrative" class="doc-narrative">{{ pro.potentialities.narrative }}</p>
            </section>
        </div>

        <footer class="doc-footer">
            <p>Documento de apoio ao acompanhamento pedagógico — contém informação pessoal.</p>
            <p>Gerado em {{ longDate(generatedAt) }}</p>
        </footer>
    </div>
</template>

<style>
/* A4, sensible margins — the actual printed page box. */
@page {
    size: A4;
    margin: 1.8cm 1.6cm;
}

.doc-page {
    max-width: 21cm;
    margin: 2rem auto;
    padding: 2cm 1.6cm 3cm;
    background: #fff;
    color: #171717;
    font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    font-size: 13px;
    line-height: 1.5;
    /* A light separation from the surrounding browser chrome on screen only —
       removed entirely under print, where there is no "page on a desk" to
       suggest (§6, §17). */
    box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.06), 0 2px 12px rgba(0, 0, 0, 0.06);
}

.doc-eyebrow {
    margin: 0;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #737373;
}

.doc-title {
    margin: 0.2rem 0 0;
    font-size: 20px;
    font-weight: 700;
}

.doc-student {
    margin: 0.15rem 0 0;
    font-size: 15px;
    font-weight: 600;
}

.doc-meta {
    margin: 0.15rem 0 0;
    font-size: 12px;
    color: #525252;
}

/* Screen only. Everything in here is removed by @media print — these are the
   controls of the person looking at the page, never part of the document. */
.doc-actions {
    margin-bottom: 1.4rem;
    padding-bottom: 1rem;
    border-bottom: 1px dashed #d4d4d4;
}

.doc-actions-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.6rem;
}

.doc-action {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    border: 1px solid #d4d4d4;
    border-radius: 0.375rem;
    background: #fff;
    padding: 0.45rem 0.8rem;
    font: inherit;
    font-size: 12.5px;
    color: #262626;
    text-decoration: none;
    cursor: pointer;
}

.doc-action:hover {
    border-color: #a3a3a3;
}

.doc-action-primary {
    background: #262626;
    border-color: #262626;
    color: #fff;
}

.doc-action-primary:hover {
    background: #404040;
    border-color: #404040;
}

.doc-actions-hint {
    margin: 0.55rem 0 0;
    font-size: 11.5px;
    color: #737373;
}

.doc-header {
    border-bottom: 1px solid #d4d4d4;
    padding-bottom: 0.9rem;
    margin-bottom: 1.1rem;
    break-after: avoid;
}

.doc-section {
    margin-bottom: 1.4rem;
    /* A SECTION FLOWS; ITS ATOMIC UNITS DO NOT SPLIT.
       This used to be `break-inside: avoid`, which reads as "keep it tidy"
       and behaves as "push the whole thing to the next page": a tall section
       — Estado 360º with its eight cards, or a long table — could not fit in
       what was left of the current page, so it jumped wholesale and left a
       third of a page blank behind it. What must never be cut in half is a
       CARD, a TABLE ROW or a LIST ITEM, and each of those protects itself
       below. The section itself is free to continue overleaf. */
    break-inside: auto;
}

/* The atomic units. A definition card, a row and a list item are each read as
   one thing, so none of them may straddle a page cut. */
.doc-grid > div,
.doc-table tr,
.doc-list li {
    break-inside: avoid;
}

.doc-heading {
    font-size: 13.5px;
    font-weight: 700;
    margin: 0 0 0.5rem;
    padding-bottom: 0.3rem;
    border-bottom: 1px solid #e5e5e5;
    break-after: avoid;
}

.doc-subheading {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #525252;
    margin: 0.6rem 0 0.2rem;
}

.doc-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.6rem 1.2rem;
    margin: 0;
}

.doc-grid > div {
    border: 1px solid #e5e5e5;
    border-radius: 4px;
    padding: 0.4rem 0.6rem;
}

.doc-grid dt {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #737373;
}

.doc-grid dd {
    margin: 0.1rem 0 0;
    font-size: 13px;
    font-weight: 600;
}

.doc-sub {
    font-size: 11px;
    font-weight: 400;
    color: #737373;
}

.doc-narrative {
    margin: 0.7rem 0 0;
    padding-top: 0.6rem;
    border-top: 1px solid #e5e5e5;
}

.doc-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.doc-table th,
.doc-table td {
    text-align: left;
    padding: 0.3rem 0.5rem;
    border-bottom: 1px solid #e5e5e5;
    vertical-align: top;
}

.doc-table th {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #737373;
}

.doc-table tr {
    break-inside: avoid;
}

.doc-highlights {
    margin: 0.6rem 0 0;
    padding-left: 1.1rem;
    font-size: 11.5px;
    color: #525252;
}

.doc-list {
    margin: 0;
    padding-left: 1.1rem;
}

.doc-list li {
    margin-bottom: 0.35rem;
    break-inside: avoid;
}

.doc-since {
    margin-top: 0.6rem;
    font-size: 12px;
}

/* Analytical sections carry no visual gimmick beyond a faint left rule — the
   distinction that matters is the wording, not the chrome (§9). */
.doc-analytical {
    border-left: 3px solid #c7c7c7;
    padding-left: 0.7rem;
}

.doc-dimensions > div {
    grid-template-columns: none;
}

/* Colour pairs with the text state — never the only carrier of meaning
   (§18): the label and the detail sentence already say what colour alone
   would otherwise carry. */
.dim-attention {
    border-color: #f0c36d !important;
    background: #fdf6e8;
}

.dim-positive {
    border-color: #86d3ae !important;
    background: #eefaf3;
}

.doc-footer {
    margin-top: 2rem;
    padding-top: 0.75rem;
    border-top: 1px solid #e5e5e5;
    font-size: 10.5px;
    color: #737373;
}

.doc-footer p {
    margin: 0.15rem 0;
}

@media print {
    body {
        background: #fff;
    }

    /* The on-screen controls and the browser-settings hint are not part of
       the document and never reach the paper. */
    .doc-actions {
        display: none !important;
    }

    .doc-page {
        margin: 0;
        max-width: none;
        padding: 0 0 2.4cm;
        box-shadow: none;
    }

    /* Repeats on every printed page in Chromium/Firefox — the closest a
       plain HTML+CSS document gets to a running footer without a paged-media
       engine this brief explicitly rules out (§14, §26). No page count: a
       reliable counter needs @page margin boxes, which are not supported
       widely enough here to promise "X de Y" honestly. */
    .doc-footer {
        position: fixed;
        left: 1.6cm;
        right: 1.6cm;
        bottom: 0.6cm;
        margin-top: 0;
    }
}
</style>
