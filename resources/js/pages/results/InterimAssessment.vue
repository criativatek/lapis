<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Camera, CircleAlert } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import BandPlates from '@/components/infographic/BandPlates.vue';
import type { BandPlate } from '@/components/infographic/BandPlates.vue';
import InfographicMetric from '@/components/infographic/InfographicMetric.vue';
import SectionHeading from '@/components/infographic/SectionHeading.vue';
import { domainColours, formatPoints, formatShare, pct, TONE_COLOURS } from '@/lib/chartTheme';
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

const plates = computed<BandPlate[]>(() => props.snapshot.distribution.map((band) => {
    const tone = qualitativeToneFor(band, bands.value);

    return {
        scale_level_id: band.scale_level_id,
        code: band.code,
        label: band.label,
        count: band.count,
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
            <button
                type="button"
                class="rounded-md border border-border px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-muted/40 hover:text-foreground"
                @click="remove"
            >
                Eliminar
            </button>
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
        <section v-if="plates.length" class="border-t border-border/70 pt-7">
            <SectionHeading
                index="02"
                title="Como se distribuíam os resultados"
                :description="`Menção de cada aluno a ${interim.reference_date_label}${snapshot.scale ? `, na escala «${snapshot.scale.name_snapshot}»` : ''}.`"
            />
            <BandPlates :plates="plates" :placed="placed" />
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
    </div>
</template>
