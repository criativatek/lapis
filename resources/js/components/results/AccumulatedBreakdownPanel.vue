<script setup lang="ts">
import { CircleAlert, Loader2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { prefersReducedMotion } from '@/lib/chartTheme';
import { ACCUMULATED, ACCUMULATED_LONG } from '@/lib/readings';

/**
 * DE ONDE VEM AQUELE NÚMERO.
 *
 * O PROBLEMA, dito com o caso real que o levantou: 68,0 % num semestre, 25,3 %
 * no outro, e 60 % de desempenho acumulado. Quem lê as três células vê um
 * número que não é a média de dois e não tem como saber porquê. A resposta não
 * cabe num tooltip — está nos pontos, e por isso este painel mostra a conta:
 * 85,00 em 125,00 no primeiro semestre, 7,33 em 29,00 no segundo, 92,33 em
 * 154,00 ao todo. É UMA fração feita dos elementos todos do ano, e cada
 * semestre pesa nela o que as suas cotações pesam.
 *
 * NÃO CALCULA NADA. Todos os números chegam prontos do servidor, do mesmo motor
 * que produziu a célula que foi clicada. Uma divisão feita aqui seria uma
 * segunda opinião sobre um número que já tem uma, e a explicação passaria a
 * poder discordar do que explica (§17).
 *
 * PEDE QUANDO ABRE. A decomposição de trezentas células não viaja com a
 * página; a de uma célula viaja quando alguém a quer (§26).
 *
 * DUAS FORMAS, PORQUE SÃO DUAS PERGUNTAS. O acumulado de um DOMÍNIO é uma
 * fração de pontos e decompõe-se em pontos. O acumulado GLOBAL é a média dos
 * domínios ponderada pelos pesos do perfil — decompô-lo em pontos seria mentir
 * sobre o que ele é, e decompõe-se em domínios.
 */

type Level = { scale_level_id: number; code: string; label: string; sequence: number; is_negative: boolean } | null;

type Unit = {
    period_id: number;
    label: string;
    kind_label: string;
    points_earned: string | null;
    points_possible: string | null;
    normalized_value: string | null;
    effective_weight_percent: string | null;
};

type Element = {
    item_code: string;
    instrument_id: number;
    instrument_title: string;
    instrument_status_label: string;
    applied_on: string;
    period_label: string;
    state: string;
    state_label: string;
    points_earned: string;
    points_possible: string;
    allocation_percent: string;
    is_bonus: boolean;
    contribution: string | null;
};

type Excluded = {
    item_code: string;
    instrument_title: string;
    applied_on: string;
    period_label: string;
    reason: string;
    reason_label: string;
    raises_coverage_warning: boolean;
};

type DomainShare = {
    domain_id: number;
    name: string;
    weight_percent: string;
    normalized_value: string | null;
    dropped: boolean;
    contribution: string | null;
};

export type Breakdown = {
    scope: 'domain' | 'overall';
    period: { id: number; ulid: string; label: string; kind_label: string };
    student: { enrollment_ulid: string; class_number: number | null };
    reading: { name: string; long_name: string; explanation: string; not_an_average: string; versus_continuous: string };
    rounding: { mode: string; mode_label: string; scale: number; stage: string; stage_label: string };
    domain: { domain_id: number; name: string } | null;
    units: Unit[];
    total: {
        points_earned: string | null;
        points_possible: string | null;
        normalized_value: string | null;
        proposed_value: string | null;
        level: Level;
        coverage_warning: boolean;
    };
    elements: Element[];
    excluded: Excluded[];
    domains: DomainShare[];
    weight_total_applied: string | null;
};

const props = defineProps<{
    /** A rota da decomposição, já montada por quem abriu o painel. Null fecha-o. */
    url: string | null;
    studentName: string;
    /** Se alguma unidade do ano tem peso formal declarado — muda a frase que contrasta as duas leituras. */
    hasDeclaredPeriodWeights: boolean;
}>();

const emit = defineEmits<{ close: [] }>();

const breakdown = ref<Breakdown | null>(null);
const loading = ref(false);
const failed = ref(false);
const showElements = ref(false);
const showCalculation = ref(false);

watch(
    () => props.url,
    async (url) => {
        breakdown.value = null;
        failed.value = false;
        showElements.value = false;
        showCalculation.value = false;

        if (url === null) {
            return;
        }

        loading.value = true;

        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });

            if (!response.ok) {
                failed.value = true;

                return;
            }

            breakdown.value = (await response.json()) as Breakdown;
        } catch {
            failed.value = true;
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);

/** Pontos, com as duas casas com que uma cotação se escreve numa ficha. */
function points(value: string | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return Number(value).toFixed(2).replace('.', ',');
}

/** Uma percentagem para ler, não para reconstruir — o valor exato vive em «Detalhes do cálculo». */
function percent(value: string | null | undefined, decimals = 1): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return `${Number(value).toFixed(decimals).replace('.', ',')} %`;
}

/** O valor sem arredondamento nenhum, tal como o motor o produziu. */
function exact(value: string | null | undefined): string {
    return value === null || value === undefined ? '—' : `${String(value).replace('.', ',')} %`;
}

const title = computed(() => {
    if (breakdown.value === null) {
        return ACCUMULATED;
    }

    return breakdown.value.domain === null
        ? `${ACCUMULATED} — resultado global`
        : `${ACCUMULATED} — ${breakdown.value.domain.name}`;
});

/**
 * A LINHA QUE FECHA A CONTA, escrita como uma conta e não como uma afirmação.
 *
 * É o que torna o número RECONSTRUÍVEL: quem tem os pontos à frente consegue
 * refazer a divisão à mão e chegar ao mesmo sítio.
 */
const formula = computed(() => {
    const data = breakdown.value;

    if (data === null || data.scope !== 'domain' || data.total.points_possible === null) {
        return null;
    }

    return `${points(data.total.points_earned)} ÷ ${points(data.total.points_possible)} = ${exact(data.total.normalized_value)}`;
});

const contrastSentence = computed(() =>
    breakdown.value === null
        ? ''
        : props.hasDeclaredPeriodWeights
          ? breakdown.value.reading.versus_continuous
          : breakdown.value.reading.not_an_average,
);
</script>

<template>
    <Transition
        :enter-active-class="prefersReducedMotion() ? '' : 'transition duration-200 ease-out'"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        :leave-active-class="prefersReducedMotion() ? '' : 'transition duration-150 ease-in'"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="url !== null"
            class="fixed inset-0 z-50 flex justify-end bg-black/40 backdrop-blur-[2px]"
            role="dialog"
            aria-modal="true"
            :aria-label="`${title} — ${studentName}`"
            @click.self="emit('close')"
            @keydown.esc="emit('close')"
        >
            <div class="h-full w-full max-w-2xl overflow-y-auto border-l border-border bg-background shadow-2xl">
                <div class="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-border bg-background px-5 py-4">
                    <div>
                        <h2 class="text-base font-semibold">{{ title }}</h2>
                        <p class="text-sm text-muted-foreground">
                            {{ studentName }}
                            <template v-if="breakdown"> · até ao fim do {{ breakdown.period.label }}</template>
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md p-1.5 text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                        aria-label="Fechar o detalhe do desempenho acumulado"
                        @click="emit('close')"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div class="space-y-5 px-5 py-4">
                    <p v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground">
                        <Loader2 class="size-4 animate-spin" />
                        A reconstruir a conta…
                    </p>

                    <p v-else-if="failed" class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        Não foi possível carregar o detalhe deste valor. Feche e tente outra vez.
                    </p>

                    <template v-else-if="breakdown">
                        <!-- O NÚMERO PRIMEIRO, e logo por baixo a frase que
                             desfaz a confusão que ele provoca (§7). -->
                        <div class="rounded-lg border border-border bg-muted/20 px-4 py-3">
                            <p class="text-3xl font-semibold tabular-nums">
                                {{ percent(breakdown.total.normalized_value) }}
                                <span
                                    v-if="breakdown.total.level"
                                    class="ml-2 align-middle text-sm font-normal text-muted-foreground"
                                >{{ breakdown.total.level.code }} — {{ breakdown.total.level.label }}</span>
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">{{ ACCUMULATED_LONG }}.</p>
                            <p class="mt-2 text-xs">{{ contrastSentence }}</p>
                            <p
                                v-if="breakdown.total.coverage_warning"
                                class="mt-2 flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-500"
                            >
                                <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
                                <span>Cobertura parcial — este valor assenta apenas em parte dos elementos aplicáveis.</span>
                            </p>
                        </div>

                        <!-- ============================ decomposição por unidade -->
                        <section v-if="breakdown.scope === 'domain'">
                            <h3 class="mb-2 text-sm font-medium">Como se chega a este valor</h3>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-border text-left text-xs text-muted-foreground">
                                        <th class="py-1.5 pr-3 font-medium" scope="col">Unidade</th>
                                        <th class="py-1.5 pr-3 text-right font-medium" scope="col">Pontos</th>
                                        <th class="py-1.5 pr-3 text-right font-medium" scope="col">Resultado</th>
                                        <th
                                            class="py-1.5 text-right font-medium"
                                            scope="col"
                                            title="A fatia do total de cotações que esta unidade ocupa. Não é um peso configurado — resulta das cotações dos elementos e muda à medida que o ano avança."
                                        >
                                            Peso efetivo
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border/60">
                                    <tr v-for="unit in breakdown.units" :key="unit.period_id">
                                        <td class="py-1.5 pr-3">{{ unit.label }}</td>
                                        <td class="py-1.5 pr-3 text-right tabular-nums">
                                            {{ points(unit.points_earned) }} / {{ points(unit.points_possible) }}
                                        </td>
                                        <td class="py-1.5 pr-3 text-right tabular-nums">{{ percent(unit.normalized_value) }}</td>
                                        <td class="py-1.5 text-right tabular-nums">{{ percent(unit.effective_weight_percent) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t-2 border-border font-medium">
                                        <td class="py-1.5 pr-3">Total</td>
                                        <td class="py-1.5 pr-3 text-right tabular-nums">
                                            {{ points(breakdown.total.points_earned) }} / {{ points(breakdown.total.points_possible) }}
                                        </td>
                                        <td class="py-1.5 pr-3 text-right tabular-nums">{{ percent(breakdown.total.normalized_value) }}</td>
                                        <td class="py-1.5 text-right tabular-nums">100,0 %</td>
                                    </tr>
                                </tfoot>
                            </table>
                            <p v-if="formula" class="mt-2 rounded-md bg-muted/40 px-3 py-2 text-xs tabular-nums">
                                Desempenho acumulado = {{ formula }}
                            </p>
                        </section>

                        <!-- ========================== decomposição por domínio -->
                        <section v-else>
                            <h3 class="mb-2 text-sm font-medium">Como se chega a este valor</h3>
                            <p class="mb-2 text-xs text-muted-foreground">
                                O resultado global não é uma fração de pontos: é a média dos domínios, ponderada pelos
                                pesos do perfil. Um domínio sem elementos sai da conta e o peso dos restantes
                                renormaliza — nunca vale zero.
                            </p>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-border text-left text-xs text-muted-foreground">
                                        <th class="py-1.5 pr-3 font-medium" scope="col">Domínio</th>
                                        <th class="py-1.5 pr-3 text-right font-medium" scope="col">Peso</th>
                                        <th class="py-1.5 pr-3 text-right font-medium" scope="col">Acumulado</th>
                                        <th class="py-1.5 text-right font-medium" scope="col">Contribuição</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border/60">
                                    <tr v-for="share in breakdown.domains" :key="share.domain_id">
                                        <td class="py-1.5 pr-3">
                                            {{ share.name }}
                                            <span v-if="share.dropped" class="ml-1 text-xs text-muted-foreground">(fora da conta)</span>
                                        </td>
                                        <td class="py-1.5 pr-3 text-right tabular-nums">{{ percent(share.weight_percent) }}</td>
                                        <td class="py-1.5 pr-3 text-right tabular-nums">{{ percent(share.normalized_value) }}</td>
                                        <td class="py-1.5 text-right tabular-nums">{{ percent(share.contribution) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t-2 border-border font-medium">
                                        <td class="py-1.5 pr-3">Total</td>
                                        <td class="py-1.5 pr-3 text-right tabular-nums">{{ percent(breakdown.weight_total_applied) }}</td>
                                        <td class="py-1.5 pr-3"></td>
                                        <td class="py-1.5 text-right tabular-nums">{{ percent(breakdown.total.normalized_value) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </section>

                        <!-- ================================= detalhes do cálculo -->
                        <section>
                            <button
                                type="button"
                                class="text-xs text-primary hover:underline focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                                :aria-expanded="showCalculation"
                                @click="showCalculation = !showCalculation"
                            >
                                {{ showCalculation ? 'Ocultar' : 'Ver' }} detalhes do cálculo
                            </button>
                            <dl v-if="showCalculation" class="mt-2 grid gap-1 rounded-md bg-muted/30 px-3 py-2 text-xs sm:grid-cols-[13rem_1fr]">
                                <dt class="text-muted-foreground">Valor antes do arredondamento</dt>
                                <dd class="tabular-nums">{{ exact(breakdown.total.normalized_value) }}</dd>
                                <dt class="text-muted-foreground">Valor apresentado</dt>
                                <dd class="tabular-nums">
                                    {{ breakdown.total.proposed_value ?? '—' }}
                                    <template v-if="breakdown.total.level">
                                        ({{ breakdown.total.level.code }} — {{ breakdown.total.level.label }})
                                    </template>
                                </dd>
                                <dt class="text-muted-foreground">Regra de arredondamento</dt>
                                <dd>{{ breakdown.rounding.mode_label }}, {{ breakdown.rounding.scale }} casas decimais</dd>
                                <dt class="text-muted-foreground">Quando é aplicada</dt>
                                <dd>{{ breakdown.rounding.stage_label }}</dd>
                            </dl>
                        </section>

                        <!-- ============================================ elementos -->
                        <section v-if="breakdown.scope === 'domain'">
                            <button
                                type="button"
                                class="text-xs text-primary hover:underline focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                                :aria-expanded="showElements"
                                @click="showElements = !showElements"
                            >
                                {{ showElements ? 'Ocultar' : 'Ver' }} os {{ breakdown.elements.length }} elementos considerados
                            </button>

                            <div v-if="showElements" class="mt-2 overflow-x-auto">
                                <table class="w-full min-w-[36rem] text-xs">
                                    <thead>
                                        <tr class="border-b border-border text-left text-muted-foreground">
                                            <th class="py-1 pr-2 font-medium" scope="col">Elemento</th>
                                            <th class="py-1 pr-2 font-medium" scope="col">Instrumento</th>
                                            <th class="py-1 pr-2 font-medium" scope="col">Data</th>
                                            <th class="py-1 pr-2 font-medium" scope="col">Unidade</th>
                                            <th class="py-1 pr-2 text-right font-medium" scope="col">Obtido / cotação</th>
                                            <th class="py-1 pr-2 text-right font-medium" scope="col">Alocação</th>
                                            <th class="py-1 text-right font-medium" scope="col">Contribuição</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border/60">
                                        <tr v-for="element in breakdown.elements" :key="`${element.instrument_id}-${element.item_code}`">
                                            <td class="py-1 pr-2 whitespace-nowrap">
                                                {{ element.item_code }}
                                                <span
                                                    v-if="element.is_bonus"
                                                    class="ml-1 rounded bg-muted px-1 text-[10px] text-muted-foreground"
                                                    title="Elemento bónus: soma ao numerador e não entra na cotação total."
                                                >bónus</span>
                                            </td>
                                            <td class="py-1 pr-2">{{ element.instrument_title }}</td>
                                            <td class="py-1 pr-2 whitespace-nowrap tabular-nums">{{ element.applied_on }}</td>
                                            <td class="py-1 pr-2 whitespace-nowrap">{{ element.period_label }}</td>
                                            <td class="py-1 pr-2 text-right whitespace-nowrap tabular-nums">
                                                {{ points(element.points_earned) }} / {{ points(element.points_possible) }}
                                            </td>
                                            <td class="py-1 pr-2 text-right tabular-nums">{{ percent(element.allocation_percent, 0) }}</td>
                                            <td class="py-1 text-right tabular-nums">{{ percent(element.contribution, 2) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p class="mt-1.5 text-[11px] text-muted-foreground">
                                    A contribuição de cada elemento é o que ele pôs no resultado, em pontos
                                    percentuais. Somadas, reproduzem o desempenho acumulado.
                                </p>
                            </div>
                        </section>

                        <!-- ====================================== não considerados -->
                        <section v-if="breakdown.excluded.length > 0">
                            <h3 class="mb-1 text-sm font-medium">Não considerados</h3>
                            <p class="mb-2 text-xs text-muted-foreground">
                                Estes elementos não entraram na conta. Nenhum deles vale zero — ficaram fora da
                                fração, e do numerador e do denominador.
                            </p>
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="border-b border-border text-left text-muted-foreground">
                                        <th class="py-1 pr-2 font-medium" scope="col">Elemento</th>
                                        <th class="py-1 pr-2 font-medium" scope="col">Instrumento</th>
                                        <th class="py-1 pr-2 font-medium" scope="col">Unidade</th>
                                        <th class="py-1 font-medium" scope="col">Motivo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border/60">
                                    <tr v-for="item in breakdown.excluded" :key="`${item.instrument_title}-${item.item_code}`">
                                        <td class="py-1 pr-2 whitespace-nowrap">{{ item.item_code }}</td>
                                        <td class="py-1 pr-2">{{ item.instrument_title }}</td>
                                        <td class="py-1 pr-2 whitespace-nowrap">{{ item.period_label }}</td>
                                        <td class="py-1">
                                            {{ item.reason_label }}
                                            <CircleAlert
                                                v-if="item.raises_coverage_warning"
                                                class="ml-0.5 inline size-3 text-amber-500"
                                                aria-label="Este é o motivo do aviso de cobertura parcial."
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>
                    </template>
                </div>
            </div>
        </div>
    </Transition>
</template>
