<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Check, Info, Lock } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';

/**
 * Administração institucional → Inteligência Artificial.
 *
 * WHAT THIS PAGE IS FOR: an institutional administrator seeing what their
 * organization has, what its ceilings are, and how much of the month is gone —
 * without reading code, and without opening a support ticket the first time a
 * teacher says «a IA parou».
 *
 * WHAT IT DELIBERATELY DOES NOT SHOW: what anybody asked, what any engine
 * answered, which teacher used what, or any pedagogical content whatsoever.
 * That is not restraint on this component's part — `ai_usage_events` has no
 * column that could hold any of it, and `AiUsageSummary` has no per-member
 * aggregation to expose. Governação é consumo e configuração; não é vigilância
 * (§20).
 *
 * NUMBERS AND WHERE THEY COME FROM ARE SHOWN TOGETHER. «Sem limite» and «limite
 * não definido» are the same number and different situations, and an
 * administrator who cannot tell them apart will read an uncapped pool as a
 * broken one.
 */

type CapabilityRow = {
    key: string;
    label: string;
    where: string;
    allowed: boolean;
    limits: { user_daily: number | null; organization_monthly: number | null };
};

type Totals = {
    calls: number;
    succeeded: number;
    failed: number;
    blocked: number;
    billable: number;
    total_tokens: number;
};

type UsageRow = Totals & { capability?: string; use_case?: string; label: string };

const props = defineProps<{
    organization: { name: string };
    capabilities: CapabilityRow[];
    pool: {
        applies: boolean;
        organization_monthly: number | null;
        user_monthly: number | null;
        used_this_month: number;
    };
    usage: {
        since: string;
        totals: Totals;
        by_capability: UsageRow[];
        by_use_case: UsageRow[];
        blocked_reasons: { reason: string; label: string; count: number }[];
    };
}>();

const allowedCapabilities = computed(() => props.capabilities.filter((capability) => capability.allowed));
const lockedCapabilities = computed(() => props.capabilities.filter((capability) => !capability.allowed));

/** A ceiling as a sentence. Null is «sem limite», not zero. */
function limitLabel(value: number | null): string {
    return value === null ? 'Sem limite' : `${value}`;
}

/**
 * How much of the pool is gone, as a percentage of it.
 *
 * ONLY WHEN THERE IS A POOL TO BE A PERCENTAGE OF. A progress bar against a
 * null ceiling would be a bar that never moves, which reads as a broken
 * feature rather than as an unlimited one.
 */
const poolPercentage = computed(() => {
    const ceiling = props.pool.organization_monthly;

    if (ceiling === null || ceiling <= 0) {
        return null;
    }

    return Math.min(100, Math.round((props.pool.used_this_month / ceiling) * 100));
});

const monthLabel = computed(() =>
    new Date(props.usage.since).toLocaleDateString('pt-PT', { month: 'long', year: 'numeric' }),
);
</script>

<template>
    <Head title="Inteligência Artificial — Administração Institucional" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <Heading title="Inteligência Artificial" :description="props.organization.name" />

        <p class="flex items-start gap-2 rounded-lg bg-muted/40 p-3 text-xs text-muted-foreground">
            <Info aria-hidden="true" class="mt-0.5 size-3.5 shrink-0" />
            <span>
                Esta página mostra <strong>consumo e configuração</strong>. Não mostra — nem pode mostrar — o que os
                professores perguntaram à IA, o que a IA respondeu, ou que professor usou o quê. Esses dados nunca são
                guardados pelo Lapispro.
            </span>
        </p>

        <!-- ------------------------------------------------ funcionalidades -->
        <section aria-labelledby="funcionalidades" class="rounded-lg border border-border p-4">
            <h2 id="funcionalidades" class="text-sm font-semibold">Funcionalidades incluídas</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">
                O que o plano desta organização inclui, e os limites em vigor para cada funcionalidade.
            </p>

            <ul v-if="allowedCapabilities.length > 0" class="mt-4 space-y-3">
                <li v-for="capability in allowedCapabilities" :key="capability.key" class="flex items-start gap-2">
                    <Check aria-hidden="true" class="mt-0.5 size-4 shrink-0 text-emerald-600" />
                    <div>
                        <p class="text-sm font-medium">{{ capability.label }}</p>
                        <p class="text-xs text-muted-foreground">{{ capability.where }}</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Por professor, por dia: {{ limitLabel(capability.limits.user_daily) }} ·
                            Da organização, por mês: {{ limitLabel(capability.limits.organization_monthly) }}
                        </p>
                    </div>
                </li>
            </ul>

            <p v-else class="mt-4 text-sm text-muted-foreground">
                O plano desta organização não inclui nenhuma funcionalidade de IA.
            </p>

            <!-- Locked capabilities are LISTED, not hidden. «Não está incluída»
                 is an answer; a capability missing from the page is a mystery,
                 and the mystery is what generates the email. -->
            <div v-if="lockedCapabilities.length > 0" class="mt-5 border-t border-border/60 pt-4">
                <h3 class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Não incluídas no plano</h3>
                <ul class="mt-2 space-y-1">
                    <li
                        v-for="capability in lockedCapabilities"
                        :key="capability.key"
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <Lock aria-hidden="true" class="size-3.5 shrink-0" />
                        {{ capability.label }}
                    </li>
                </ul>
            </div>
        </section>

        <!-- --------------------------------------------------------- plafond -->
        <section v-if="props.pool.applies" aria-labelledby="plafond" class="rounded-lg border border-border p-4">
            <h2 id="plafond" class="text-sm font-semibold">Plafond de IA da organização</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">
                Um limite mensal para o conjunto de todas as funcionalidades de IA, além dos limites de cada uma.
            </p>

            <div class="mt-4 space-y-2">
                <p class="text-sm">
                    <strong>{{ props.pool.used_this_month }}</strong>
                    {{ props.pool.organization_monthly === null ? 'pedidos este mês' : `de ${props.pool.organization_monthly} pedidos este mês` }}
                </p>

                <div v-if="poolPercentage !== null" class="h-2 w-full overflow-hidden rounded-full bg-muted">
                    <div class="h-full rounded-full bg-primary" :style="{ width: `${poolPercentage}%` }"></div>
                </div>

                <p v-else class="text-xs text-muted-foreground">
                    Ainda não está definido um plafond mensal para esta organização. Os limites por funcionalidade,
                    acima, continuam a aplicar-se.
                </p>

                <p v-if="props.pool.user_monthly !== null" class="text-xs text-muted-foreground">
                    Cada professor pode usar no máximo {{ props.pool.user_monthly }} pedidos por mês dentro deste
                    plafond.
                </p>
            </div>
        </section>

        <!-- ----------------------------------------------------------- uso -->
        <section aria-labelledby="uso" class="rounded-lg border border-border p-4">
            <h2 id="uso" class="text-sm font-semibold">Utilização em {{ monthLabel }}</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">
                Contagens agregadas da organização. Sem conteúdo, sem nomes e sem detalhe por professor.
            </p>

            <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-lg bg-muted/40 p-3">
                    <dt class="text-xs text-muted-foreground">Pedidos</dt>
                    <dd class="text-lg font-semibold">{{ props.usage.totals.calls }}</dd>
                </div>
                <div class="rounded-lg bg-muted/40 p-3">
                    <dt class="text-xs text-muted-foreground">Concluídos</dt>
                    <dd class="text-lg font-semibold">{{ props.usage.totals.succeeded }}</dd>
                </div>
                <div class="rounded-lg bg-muted/40 p-3">
                    <dt class="text-xs text-muted-foreground">Com erro</dt>
                    <dd class="text-lg font-semibold">{{ props.usage.totals.failed }}</dd>
                </div>
                <div class="rounded-lg bg-muted/40 p-3">
                    <dt class="text-xs text-muted-foreground">Recusados por limite</dt>
                    <dd class="text-lg font-semibold">{{ props.usage.totals.blocked }}</dd>
                </div>
            </dl>

            <div v-if="props.usage.by_capability.length > 0" class="mt-5 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs text-muted-foreground">
                            <th scope="col" class="py-1.5 pr-3 font-medium">Funcionalidade</th>
                            <th scope="col" class="py-1.5 pr-3 text-right font-medium">Pedidos</th>
                            <th scope="col" class="py-1.5 pr-3 text-right font-medium">Concluídos</th>
                            <th scope="col" class="py-1.5 pr-3 text-right font-medium">Erros</th>
                            <th scope="col" class="py-1.5 text-right font-medium">Recusados</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in props.usage.by_capability" :key="row.capability" class="border-b border-border/50">
                            <th scope="row" class="py-1.5 pr-3 text-left font-normal">{{ row.label }}</th>
                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ row.calls }}</td>
                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ row.succeeded }}</td>
                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ row.failed }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ row.blocked }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- WHY calls were refused, so «o limite está mal posto» is visible
                 as a number rather than as a complaint. Never who was refused. -->
            <div v-if="props.usage.blocked_reasons.length > 0" class="mt-5 border-t border-border/60 pt-4">
                <h3 class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Motivos das recusas</h3>
                <ul class="mt-2 space-y-1">
                    <li v-for="row in props.usage.blocked_reasons" :key="row.reason" class="text-sm">
                        — {{ row.label }}: {{ row.count }}
                    </li>
                </ul>
            </div>
        </section>
    </div>
</template>
