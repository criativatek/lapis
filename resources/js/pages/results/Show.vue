<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CircleAlert } from '@lucide/vue';
import Heading from '@/components/Heading.vue';

type DomainCol = { id: number; name: string };
type DomainValue = { domain_id: number; value: string | null; warning: boolean };
type Row = {
    name: string;
    class_number: number | null;
    overall: string | null;
    proposed: string | null;
    has_value: boolean;
    coverage_warning: boolean;
    domains: DomainValue[];
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string; has_profile: boolean };
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
                <Link :href="`/classes/${schoolClass.ulid}`" class="text-sm text-muted-foreground hover:underline">← Voltar à turma</Link>
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
                            <span class="text-muted-foreground">{{ row.class_number ?? '—' }}</span>
                            <span class="ml-2 font-medium">{{ row.name }}</span>
                        </td>
                        <td v-for="domain in domains" :key="domain.id" class="px-3 py-2 text-center tabular-nums">
                            <span :class="{ 'text-muted-foreground': domainValue(row, domain.id)?.value === null }">
                                {{ pct(domainValue(row, domain.id)?.value ?? null) }}
                            </span>
                            <CircleAlert
                                v-if="domainValue(row, domain.id)?.warning"
                                class="ml-0.5 inline size-3 text-amber-500"
                            />
                        </td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums">
                            <span :class="{ 'text-muted-foreground': !row.has_value }">{{ pct(row.overall) }}</span>
                            <CircleAlert v-if="row.coverage_warning" class="ml-0.5 inline size-3 text-amber-500" title="Cobertura insuficiente — faltam elementos." />
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            <span v-if="row.proposed !== null" class="rounded bg-muted px-2 py-0.5">{{ row.proposed }}%</span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="flex items-start gap-2 text-xs text-muted-foreground">
            <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
            A proposta é o valor calculado, arredondado. O nível (Muito Bom, etc.) não é atribuído automaticamente —
            depende das bandas de escala, ainda por definir. "—" significa sem elementos, nunca zero. O
            <CircleAlert class="inline size-3 text-amber-500" /> assinala cobertura insuficiente.
        </p>
    </div>
</template>
