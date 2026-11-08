<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Camera, CircleAlert, Printer } from '@lucide/vue';
import { computed, nextTick, onMounted, ref } from 'vue';
import PrintableDocument from '@/components/documents/PrintableDocument.vue';
import Heading from '@/components/Heading.vue';
import DistributionBands from '@/components/infographic/DistributionBands.vue';
import type { DistributionBand } from '@/components/infographic/DistributionBands.vue';
import InfographicMetric from '@/components/infographic/InfographicMetric.vue';
import SectionHeading from '@/components/infographic/SectionHeading.vue';
import { domainShort, mentionShort, uniqueMentionShorts } from '@/lib/abbreviations';
import { domainColours, formatPoints, formatShare, pct, TONE_COLOURS } from '@/lib/chartTheme';
import { SAFETY_PX, fitNotice, fitToPage, usableAreaPx } from '@/lib/printFit';
import { qualitativeToneClasses, qualitativeToneFor } from '@/lib/qualitativeTone';

/**
 * A kept moment, opened again.
 *
 * EVERY NUMBER HERE COMES OUT OF THE STORED DOCUMENT and nothing is rebuilt —
 * asking today's data what November looked like is the one thing this whole
 * feature exists to avoid. The words come from the document too: a domain
 * renamed since is still shown under the name it had.
 */

type Band = {
    scale_level_id: number | null;
    code_snapshot: string | null;
    label_snapshot: string | null;
    sequence: number | null;
    is_negative: boolean | null;
} | null;

type Snapshot = {
    version: number;
    reference_date: string;
    class: { label_snapshot: string; subject_label_snapshot: string };
    period: { label_snapshot: string };
    scale: { name_snapshot: string; bands: { label_snapshot: string; sequence: number; is_negative: boolean }[] } | null;
    domains: { domain_id: number; label_snapshot: string }[];
    summary: {
        students_total: number; students_with_result: number; students_without_result: number;
        class_average: string | null; accumulated_average: string | null;
        partial_coverage_count: number;
        most_common_band: { code: string; label: string; sequence: number; is_negative: boolean; count: number } | null;
    };
    distribution: { scale_level_id: number; code: string; label: string; sequence: number; is_negative: boolean; count: number; percentage: string | null }[];
    domain_statistics: {
        domain_id: number; label: string;
        period_average: string | null; accumulated_average: string | null; evolution_average: string | null;
        students_with_result: number; partial_coverage_count: number;
        qualitative_band: { label: string; sequence: number; is_negative: boolean } | null;
    }[];
    students: {
        enrollment_id: number; name_snapshot: string; class_number: number | null;
        weighted_average: string | null; accumulated_average: string | null;
        coverage_warning: boolean; band: Band;
        domains: { domain_id: number; label_snapshot: string | null; weighted_average: string | null; mention: Band }[];
    }[];
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string };
    decision: { label: string; classifies_by_level: boolean };
    canExportToInovar: boolean;
    periodUlid: string;
    interim: {
        ulid: string; name: string; reference_date: string; reference_date_label: string;
        note: string | null; created_at: string; snapshot_version: number; is_intact: boolean;
    };
    snapshot: Snapshot;
}>();

const bands = computed(() => props.snapshot.scale?.bands.map((band) => ({
    sequence: band.sequence,
    is_negative: band.is_negative,
})) ?? []);

const inks = computed(() => domainColours(props.snapshot.domains.map((domain) => domain.domain_id)));

function toneClass(band: { sequence: number | null; is_negative: boolean | null } | null): string {
    if (band === null || band.sequence === null || band.is_negative === null) {
        return 'bg-muted text-muted-foreground';
    }

    return qualitativeToneClasses[qualitativeToneFor({ sequence: band.sequence, is_negative: band.is_negative }, bands.value)];
}

const distributionBands = computed<DistributionBand[]>(() => props.snapshot.distribution.map((band) => {
    const tone = qualitativeToneFor(band, bands.value);

    return {
        key: String(band.scale_level_id),
        scale_level_id: band.scale_level_id,
        code: band.code,
        label: band.label,
        count: band.count,
        percent: band.percentage === null ? null : Number(band.percentage),
        share: formatShare(band.percentage),
        tone,
        colour: TONE_COLOURS[tone].border,
    };
}));

const placed = computed(() => props.snapshot.distribution.reduce((total, band) => total + band.count, 0));

function remove(): void {
    if (! window.confirm('Eliminar esta avaliação intercalar? A fotografia perde-se.')) {
        return;
    }

    router.delete(`/classes/${props.schoolClass.ulid}/avaliacoes-intercalares/${props.interim.ulid}`);
}

// ---------------------------------------------------------------- impressão
//
// UMA FOLHA, E SÓ O MAPA (0.191.2). O ecrã é um painel — cartões, gráfico,
// domínios — e nada disso é o que se leva para a reunião; o que se leva é cada
// aluno em cada domínio naquela data, com a menção. Por isso o papel não é o
// ecrã: é um documento próprio, invisível no ecrã, só com o mapa, em siglas e
// a 7,5 pt, encolhido até caber numa A4 horizontal — a mesma mecânica da grelha
// de correção e da pauta (`printFit`). TUDO SAI DO RETRATO: nomes, domínios e
// menções são os que ficaram guardados, nunca os de hoje.

const printedOn = new Date().toLocaleDateString('pt-PT');

/** Sigla de cada menção da escala guardada; o rótulo inteiro se duas colidissem. */
const mentionShorts = computed(() =>
    uniqueMentionShorts((props.snapshot.scale?.bands ?? []).map((band) => ({ code: band.label_snapshot, label: band.label_snapshot }))),
);

/** Sigla de cada domínio, sem duas iguais no mesmo mapa. */
const domainShorts = computed(() => {
    const taken: string[] = [];
    const shorts = new Map<number, string>();

    for (const domain of props.snapshot.domains) {
        const short = domainShort(domain.label_snapshot, taken);
        taken.push(short);
        shorts.set(domain.domain_id, short);
    }

    return shorts;
});

function domainShortOf(domain: { domain_id: number; label_snapshot: string }): string {
    return domainShorts.value.get(domain.domain_id) ?? domain.label_snapshot;
}

/** A sigla de uma menção guardada; «—» sem menção. */
function mentionShortOf(band: Band): string {
    const label = band?.label_snapshot ?? null;

    if (label === null || label === '') {
        return band?.code_snapshot ?? '—';
    }

    return mentionShorts.value.get(label) ?? mentionShort(label);
}

/** «78,0% · B» para um aluno num domínio; «—» quando não havia nada nessa data. */
function printedDomainCell(student: Snapshot['students'][number], domainId: number): string {
    const cell = student.domains.find((candidate) => candidate.domain_id === domainId);
    const value = cell?.weighted_average ?? null;
    const mention = cell?.mention ?? null;

    if (value === null && mention === null) {
        return '—';
    }

    return mention === null ? pct(value) : `${pct(value)} · ${mentionShortOf(mention)}`;
}

/** «3 · S» — o código e a sigla da menção guardada; «—» sem menção. */
function printedMention(student: Snapshot['students'][number]): string {
    if (student.band === null) {
        return '—';
    }

    const code = student.band.code_snapshot;
    const short = mentionShortOf(student.band);

    return code === null || code === short ? short : `${code} · ${short}`;
}

const printedLegend = computed(() => {
    const parts: string[] = [];

    for (const domain of props.snapshot.domains) {
        const short = domainShortOf(domain);

        if (short !== domain.label_snapshot) {
            parts.push(`${short} = ${domain.label_snapshot}`);
        }
    }

    for (const band of props.snapshot.scale?.bands ?? []) {
        const short = mentionShorts.value.get(band.label_snapshot);

        if (short !== undefined && short !== band.label_snapshot) {
            parts.push(`${short} = ${band.label_snapshot}`);
        }
    }

    return parts;
});

const anyPartial = computed(() => props.snapshot.students.some((student) => student.coverage_warning));

const printDocumentRef = ref<InstanceType<typeof PrintableDocument> | null>(null);
const fitRef = ref<HTMLElement | null>(null);
const fit = ref(1);
const notice = ref<string | null>(null);

/**
 * Mede o documento COMO ELE SAI NO PAPEL. No ecrã está escondido; a classe
 * `interim-measuring` mostra-o só durante este passo síncrono — sem um quadro
 * pintado pelo meio — à largura do papel.
 */
function measure(): void {
    const root = printDocumentRef.value?.$el as HTMLElement | null | undefined;
    const box = fitRef.value;
    const table = box?.querySelector<HTMLTableElement>('table') ?? null;

    if (!root || box === null || table === null) {
        fit.value = 1;
        notice.value = null;

        return;
    }

    const usable = usableAreaPx('A4', 'landscape');
    const previousRootWidth = root.style.width;

    root.classList.add('interim-measuring');
    root.style.width = `${usable.width}px`;

    const tableWidth = table.offsetWidth;
    const rootRect = root.getBoundingClientRect();
    const fitRect = box.getBoundingClientRect();
    const headerHeight = fitRect.top - rootRect.top;
    const footerHeight = rootRect.bottom - fitRect.bottom;
    const tableHeight = fitRect.height;

    root.style.width = previousRootWidth;
    root.classList.remove('interim-measuring');

    const result = fitToPage({
        tableWidth,
        tableHeight,
        headerHeight,
        footerHeight,
        slack: SAFETY_PX,
        usable,
    });

    fit.value = result.scale;
    notice.value = fitNotice(result);
}

onMounted(() => void nextTick(measure));

function print(): void {
    measure();
    void nextTick(() => window.print());
}
</script>

<template>
    <Head :title="`${interim.name} — ${schoolClass.label}`" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Heading :title="interim.name" :description="`${schoolClass.label} · ${schoolClass.subject}`" />
                <div class="flex flex-wrap gap-3 text-sm">
                    <Link :href="`/classes/${schoolClass.ulid}/results/estatistica`" class="text-muted-foreground hover:underline">
                        ← Voltar à Estatística
                    </Link>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Link
                    :href="`/classes/${schoolClass.ulid}/avaliacoes-intercalares/${interim.ulid}/comparar`"
                    class="rounded-md border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90"
                >
                    Comparar com o final do {{ snapshot.period.label_snapshot }}
                </Link>
                <!-- Only where the capability is there: a school that never
                     uses INOVAR never meets this. -->
                <Link
                    v-if="canExportToInovar"
                    :href="`/classes/${schoolClass.ulid}/exports/inovar/${periodUlid}?intercalar=${interim.ulid}`"
                    class="rounded-md border border-border px-3 py-1.5 text-sm transition-colors hover:bg-muted/40"
                >
                    Exportar para INOVAR
                </Link>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm transition-colors hover:bg-muted/40"
                    @click="print"
                >
                    <Printer class="size-4" /> Imprimir
                </button>
                <button
                    type="button"
                    class="rounded-md border border-border px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-muted/40 hover:text-foreground"
                    @click="remove"
                >
                    Eliminar
                </button>
                <p v-if="notice" data-fit-notice class="basis-full text-xs text-muted-foreground" role="status">{{ notice }}</p>
            </div>
        </div>

        <!--
          NEVER PRESENTED AS THE CURRENT STATE. The banner is the first thing on
          the page and says what this is and when it was taken (§22).
        -->
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-primary/5 px-4 py-3">
            <Camera class="size-4 shrink-0 text-primary" />
            <p class="text-sm font-medium">
                Avaliação intercalar · {{ interim.reference_date_label }}
            </p>
            <p class="text-xs text-muted-foreground">
                {{ snapshot.period.label_snapshot }} — fotografia do estado nesta data. Não reflete alterações posteriores.
            </p>
        </div>

        <p v-if="interim.note" class="rounded-xl bg-muted/25 px-4 py-3 text-sm">{{ interim.note }}</p>

        <p
            v-if="!interim.is_intact"
            class="flex items-start gap-2 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
        >
            <CircleAlert class="mt-0.5 size-4 shrink-0" />
            Esta fotografia já não corresponde à assinatura com que foi guardada. Trate os valores abaixo com reserva.
        </p>

        <!-- ============================================ 01 · o resumo -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <InfographicMetric
                index="01"
                label="Média Ponderada da turma"
                :value="pct(snapshot.summary.class_average)"
                :context="`${snapshot.period.label_snapshot}, só com este período`"
            />
            <InfographicMetric
                index="02"
                label="Média acumulada"
                :value="pct(snapshot.summary.accumulated_average)"
                context="Tudo o que contava até esta data"
            />
            <InfographicMetric index="03" label="Alunos com resultado" value="">
                <template #value>
                    {{ snapshot.summary.students_with_result }}<span class="text-lg font-normal text-muted-foreground">/{{ snapshot.summary.students_total }}</span>
                </template>
                <p class="mt-2 text-xs text-muted-foreground">
                    {{ snapshot.summary.partial_coverage_count }} com informação parcial nesta data
                </p>
            </InfographicMetric>
            <InfographicMetric
                index="04"
                :label="snapshot.summary.most_common_band ? 'Menção mais frequente' : 'Informação parcial'"
                :value="snapshot.summary.most_common_band ? '' : String(snapshot.summary.partial_coverage_count)"
            >
                <template v-if="snapshot.summary.most_common_band" #value>
                    <span class="flex items-center gap-2">
                        {{ snapshot.summary.most_common_band.code }}
                        <span class="rounded-md px-2 py-0.5 text-xs font-medium" :class="toneClass(snapshot.summary.most_common_band as never)">
                            {{ snapshot.summary.most_common_band.label }}
                        </span>
                    </span>
                </template>
            </InfographicMetric>
        </div>

        <!-- ============================== 02 · distribuição naquela data -->
        <section v-if="distributionBands.length" class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm">
            <SectionHeading
                index="02"
                title="Como se distribuíam os resultados"
                :description="`Menção de cada aluno a ${interim.reference_date_label}${snapshot.scale ? `, na escala «${snapshot.scale.name_snapshot}»` : ''}.`"
            />
            <!-- The same rows the live page uses: one visual system serves
                 today, a cutoff, a photograph and a comparison (§33). -->
            <DistributionBands :bands="distributionBands" :placed="placed" />
        </section>

        <!-- ============================== 03 · domínios naquela data -->
        <section class="border-t border-border/70 pt-7">
            <SectionHeading index="03" title="Domínios" :description="`Médias a ${interim.reference_date_label}.`" />

            <ul class="space-y-2">
                <li
                    v-for="domain in snapshot.domain_statistics"
                    :key="domain.domain_id"
                    class="flex flex-wrap items-center gap-3 rounded-lg bg-muted/20 px-4 py-2.5"
                >
                    <span class="size-2 shrink-0 rounded-full" :style="{ backgroundColor: inks[domain.domain_id] }"></span>
                    <span class="text-xs font-semibold uppercase tracking-wider">{{ domain.label }}</span>
                    <span
                        v-if="domain.qualitative_band"
                        class="rounded-md px-1.5 py-0.5 text-[11px] font-medium"
                        :class="toneClass(domain.qualitative_band as never)"
                    >{{ domain.qualitative_band.label }}</span>
                    <span v-if="domain.evolution_average !== null" class="text-[11px] tabular-nums text-muted-foreground">
                        {{ formatPoints(domain.evolution_average) }} p.p.
                    </span>
                    <span class="ml-auto text-sm font-semibold tabular-nums">{{ pct(domain.period_average) }}</span>
                </li>
            </ul>
        </section>

        <!-- ============================== 04 · o mapa naquela data -->
        <section class="border-t border-border/70 pt-7">
            <SectionHeading
                index="04"
                title="Mapa da turma"
                :description="`Cada aluno em cada domínio, a ${interim.reference_date_label}. Os nomes são os que existiam nessa data.`"
            />

            <div class="-mx-2 overflow-x-auto px-2">
                <table class="w-max min-w-full border-separate border-spacing-y-1 text-sm">
                    <thead>
                        <tr class="text-left">
                            <th scope="col" class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Aluno</th>
                            <th
                                v-for="domain in snapshot.domains"
                                :key="domain.domain_id"
                                scope="col"
                                class="px-2 pb-2 text-center text-[11px] font-semibold uppercase tracking-wider text-muted-foreground"
                            >
                                <span class="mr-1 inline-block size-1.5 rounded-full align-middle" :style="{ backgroundColor: inks[domain.domain_id] }"></span>
                                {{ domain.label_snapshot }}
                            </th>
                            <th scope="col" class="px-3 pb-2 text-center text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Menção</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="student in snapshot.students" :key="student.enrollment_id">
                            <th scope="row" class="px-3 py-1 text-left font-normal">
                                <span class="mr-1.5 tabular-nums text-xs text-muted-foreground">{{ student.class_number ?? '—' }}</span>
                                {{ student.name_snapshot }}
                                <CircleAlert v-if="student.coverage_warning && student.weighted_average !== null" class="ml-1 inline size-3 text-amber-500" />
                            </th>
                            <td v-for="domain in snapshot.domains" :key="domain.domain_id" class="px-1.5 py-1 text-center">
                                <span
                                    class="inline-flex min-w-16 items-center justify-center rounded-lg px-2 py-1.5 text-xs tabular-nums"
                                    :class="toneClass(student.domains.find((cell) => cell.domain_id === domain.domain_id)?.mention ?? null)"
                                >
                                    {{ pct(student.domains.find((cell) => cell.domain_id === domain.domain_id)?.weighted_average ?? null) }}
                                </span>
                            </td>
                            <td class="px-3 py-1 text-center">
                                <span v-if="student.band" class="rounded-md px-2 py-1 text-xs font-medium" :class="toneClass(student.band)">
                                    {{ student.band.code_snapshot }} · {{ student.band.label_snapshot }}
                                </span>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <p class="text-xs text-muted-foreground">
            Guardada a partir do estado de {{ interim.reference_date_label }} · formato v{{ interim.snapshot_version }}.
        </p>

        <!-- O PAPEL. Invisível no ecrã (`hidden print:block`); ao imprimir, o
             PrintableDocument esconde tudo o resto da página e fica só isto:
             o mapa daquela data, em siglas, numa A4 horizontal. -->
        <PrintableDocument
            ref="printDocumentRef"
            orientation="landscape"
            compact
            :fit="fit"
            class="interim-print hidden space-y-2 print:block"
            data-test="print-document"
        >
            <div class="interim-print-header">
                <h1 class="text-base leading-tight font-semibold">Avaliação intercalar — {{ snapshot.class.label_snapshot }}</h1>
                <p class="text-xs">{{ snapshot.class.subject_label_snapshot }} · {{ snapshot.period.label_snapshot }}</p>
                <p class="text-xs">
                    <span class="font-medium">Referência: {{ interim.reference_date_label }}</span> — fotografia do estado nessa
                    data; não reflete alterações posteriores. · Impresso em {{ printedOn }}
                </p>
            </div>

            <div ref="fitRef" class="document-print-fit">
                <table class="w-max border-collapse text-[7.5pt] leading-tight" data-test="print-table">
                    <thead>
                        <tr class="bg-muted text-left">
                            <th class="border-b border-border px-[2pt] py-[1pt] align-bottom font-medium">Aluno</th>
                            <th
                                v-for="domain in snapshot.domains"
                                :key="`print-${domain.domain_id}`"
                                class="border-b border-l border-border px-[2pt] py-[1pt] text-center font-semibold"
                                :title="domain.label_snapshot"
                                :abbr="domain.label_snapshot"
                            >
                                {{ domainShortOf(domain) }}
                            </th>
                            <th class="border-b border-l-2 border-border px-[2pt] py-[1pt] text-center font-semibold">Menção</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(student, index) in snapshot.students" :key="`print-${student.enrollment_id}`" :class="index % 2 === 1 ? 'bg-muted/10' : ''">
                            <td class="border-b border-border px-[2pt] py-[1pt]">
                                <span class="block max-w-[24ch] truncate" :title="student.name_snapshot">
                                    <span class="text-muted-foreground">{{ student.class_number ?? '—' }}</span>
                                    <span class="ml-1 font-medium">{{ student.name_snapshot }}</span>
                                </span>
                            </td>
                            <td
                                v-for="domain in snapshot.domains"
                                :key="`print-${student.enrollment_id}-${domain.domain_id}`"
                                class="border-b border-l border-border px-[2pt] py-[1pt] text-center whitespace-nowrap tabular-nums"
                                :class="toneClass(student.domains.find((cell) => cell.domain_id === domain.domain_id)?.mention ?? null)"
                            >
                                {{ printedDomainCell(student, domain.domain_id) }}
                            </td>
                            <td
                                class="border-b border-l-2 border-border px-[2pt] py-[1pt] text-center font-medium whitespace-nowrap tabular-nums"
                                :class="toneClass(student.band)"
                            >
                                {{ printedMention(student) }}<span v-if="student.coverage_warning" aria-label="informação parcial nesta data">*</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="pt-1 text-[6.5pt] leading-snug" data-abbreviations>
                    <strong>Siglas:</strong>{{ ' ' }}
                    <template v-for="(part, index) in printedLegend" :key="part">{{ index > 0 ? ' · ' : '' }}{{ part }}</template>
                    <template v-if="anyPartial"><template v-if="printedLegend.length > 0"> · </template>* = informação parcial nesta data</template>
                </p>
            </div>
        </PrintableDocument>
    </div>
</template>

<style>
/* O que é próprio da intercalar no papel. O isolamento da folha, o `@page` e a
   escala (`document-print-fit`) são do PrintableDocument; tudo preso a
   `.interim-print`, porque o <style> fica carregado na SPA depois de a página
   sair. */
@media print {
    /* Os tons das menções ficam: identificam a menção ao lado do texto. */
    .interim-print,
    .interim-print * {
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
}
/* A MEDIÇÃO: durante um único passo síncrono de `measure()`, o documento — que
   no ecrã está escondido — fica visível para se medir como sai no papel. */
.interim-print.interim-measuring {
    display: block;
}
</style>
