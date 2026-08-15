<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CircleAlert } from '@lucide/vue';
import CoverageWarning from '@/components/CoverageWarning.vue';
import Heading from '@/components/Heading.vue';
import StudentAvatar from '@/components/StudentAvatar.vue';
import type { Coverage } from '@/types';

type DomainCol = { id: number; name: string };
type DomainValue = { domain_id: number; value: string | null; warning: boolean; coverage: Coverage };
type Proposal = {
    value: string | null;
    state: 'resolved' | 'unconfigured' | 'no_result';
    is_percentage: boolean;
};
type Row = {
    name: string;
    photo_url: string | null;
    class_number: number | null;
    overall: string | null;
    proposal: Proposal;
    has_value: boolean;
    coverage_warning: boolean;
    coverage: Coverage;
    domains: DomainValue[];
};

// A row whose warning has no detail still gets a tooltip, not a blank one.
const NO_COVERAGE: Coverage = { absences: [], no_elements: false, excluded_domain_ids: [] };

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string; has_profile: boolean; scale_name: string | null };
    periods: { ulid: string; label: string; selected: boolean }[];
    domains: DomainCol[];
    rows: Row[];
}>();

// Trim the engine's 6-decimal value to something a teacher reads. "—" for null,
// never 0, because a missing value is not a zero.
function pct(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return `${Number(value).toFixed(1)}%`;
}

function domainValue(row: Row, domainId: number): DomainValue | undefined {
    return row.domains.find((domain) => domain.domain_id === domainId);
}

function domainCoverage(row: Row, domainId: number): Coverage {
    return domainValue(row, domainId)?.coverage ?? NO_COVERAGE;
}

function selectPeriod(ulid: string): void {
    router.get(`/classes/${props.schoolClass.ulid}/results/${ulid}`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Resultados — ${schoolClass.label}`" />

    <div class="space-y-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <Heading :title="`Resultados — ${schoolClass.label}`" :description="schoolClass.subject" />
                <div class="flex gap-3 text-sm">
                    <Link :href="`/classes/${schoolClass.ulid}`" class="text-muted-foreground hover:underline">← Voltar à turma</Link>
                    <Link :href="`/classes/${schoolClass.ulid}/classifications`" class="text-primary hover:underline">Classificações →</Link>
                </div>
            </div>
            <div v-if="periods.length" class="flex gap-1">
                <button
                    v-for="period in periods"
                    :key="period.ulid"
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm"
                    :class="period.selected ? 'border-primary bg-primary text-primary-foreground' : 'border-border hover:bg-muted/40'"
                    @click="selectPeriod(period.ulid)"
                >
                    {{ period.label }}
                </button>
            </div>
        </div>

        <p v-if="!schoolClass.has_profile" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Esta turma não tem perfil de avaliação associado, por isso não há classificações a calcular.
        </p>

        <div v-else-if="rows.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <p class="text-sm text-muted-foreground">Sem alunos ou sem resultados neste período.</p>
        </div>

        <div v-else class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="sticky left-0 z-10 bg-muted/50 px-3 py-2 font-medium">Aluno</th>
                        <th v-for="domain in domains" :key="domain.id" class="px-3 py-2 text-center font-medium">{{ domain.name }}</th>
                        <th class="px-3 py-2 text-right font-medium">Resultado</th>
                        <th class="px-3 py-2 text-right font-medium">Proposta</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="row in rows" :key="row.name" class="hover:bg-muted/20">
                        <td class="sticky left-0 z-10 bg-background px-3 py-2 whitespace-nowrap">
                            <div class="flex items-center gap-1.5">
                                <span class="text-muted-foreground">{{ row.class_number ?? '—' }}</span>
                                <StudentAvatar :photo-url="row.photo_url" size="xs" />
                                <span class="font-medium">{{ row.name }}</span>
                            </div>
                        </td>
                        <td v-for="domain in domains" :key="domain.id" class="px-3 py-2 text-center tabular-nums">
                            <span :class="{ 'text-muted-foreground': domainValue(row, domain.id)?.value === null }">
                                {{ pct(domainValue(row, domain.id)?.value ?? null) }}
                            </span>
                            <CoverageWarning
                                v-if="domainValue(row, domain.id)?.warning"
                                :coverage="domainCoverage(row, domain.id)"
                                :has-value="(domainValue(row, domain.id)?.value ?? null) !== null"
                                :domains="domains"
                            />
                        </td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums">
                            <span :class="{ 'text-muted-foreground': !row.has_value }">{{ pct(row.overall) }}</span>
                            <CoverageWarning
                                v-if="row.coverage_warning"
                                :coverage="row.coverage"
                                :has-value="row.has_value"
                                :domains="domains"
                                scope="overall"
                            />
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            <span v-if="row.proposal.value !== null" class="rounded bg-muted px-2 py-0.5">
                                {{ row.proposal.value }}<template v-if="row.proposal.is_percentage">%</template>
                            </span>
                            <span
                                v-else-if="row.proposal.state === 'unconfigured'"
                                class="text-muted-foreground"
                                title="Escala de classificação por configurar — o nível é atribuído pelo professor."
                            >—</span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="flex items-start gap-2 text-xs text-muted-foreground">
            <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
            <span>
                O <strong>Resultado</strong> é o valor normalizado, em percentagem. A
                <strong>Proposta</strong> traduz esse resultado para a escala de classificação
                definida no perfil de avaliação<template v-if="schoolClass.scale_name">
                ({{ schoolClass.scale_name }})</template>. Quando a escala ainda não tem bandas
                definidas, a proposta fica por atribuir — o LÁPIS não infere limiares.
                "—" significa sem elementos, nunca zero. O
                <CircleAlert class="inline size-3 text-amber-500" /> assinala
                <strong>cobertura parcial</strong> — há resultado, mas assenta apenas em parte dos
                elementos aplicáveis — ou a ausência de elementos avaliados. Passa o rato ou o foco
                por cima para ver o detalhe.
            </span>
        </p>
    </div>
</template>
