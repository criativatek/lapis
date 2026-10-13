<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import UsageSegmented from '@/components/admin/usage/UsageSegmented.vue';
import { Button } from '@/components/ui/button';
import { commonQuery } from '@/lib/usageFormat';
import type { UsageClassificationKey, UsageFilterOptions, UsageFilters, UsagePeriodKey, UsageSegmentKey } from '@/types/usage';

/**
 * Os filtros comuns às quatro páginas. Mudar uma opção aplica logo; o período
 * personalizado espera por «Aplicar», porque duas datas a meio não são um filtro.
 * `extra` leva o que é próprio da página (pesquisa, sinal, ordenação) para que
 * mudar o período não o perca; a página volta à primeira.
 */
const props = defineProps<{
    path: string;
    filters: UsageFilters;
    options: UsageFilterOptions;
    extra?: Record<string, string | null | undefined>;
}>();

const PERIODS: { value: UsagePeriodKey; label: string }[] = [
    { value: '7', label: 'Últimos 7 dias' },
    { value: '30', label: 'Últimos 30 dias' },
    { value: '90', label: 'Últimos 90 dias' },
    { value: 'custom', label: 'Personalizado' },
];

const CLASSIFICATION_SUFFIX: Record<UsageClassificationKey, string> = {
    real: '',
    test: ' (teste)',
    internal: ' (interna)',
    unclassified: ' (por classificar)',
};

const form = reactive({
    period: props.filters.period,
    from: props.filters.from,
    to: props.filters.to,
    plan: props.filters.plan ?? '',
    organization: props.filters.organization ?? '',
    segment: props.filters.segment,
});

const errors = computed(() => (usePage().props.errors ?? {}) as Record<string, string>);
const errorMessages = computed(() => Object.values(errors.value).filter((message) => typeof message === 'string' && message !== ''));

const segmentHelp = computed(() => props.options.segments.find((segment) => segment.key === form.segment)?.description ?? '');

const segmentOptions = computed(() => props.options.segments.map((segment) => ({ key: segment.key, label: segment.label })));

function apply(): void {
    const query: Record<string, string> = commonQuery({
        ...props.filters,
        period: form.period,
        from: form.from,
        to: form.to,
        plan: form.plan || null,
        organization: form.organization || null,
        segment: form.segment,
    });

    Object.entries(props.extra ?? {}).forEach(([key, value]) => {
        if (value) {
            query[key] = value;
        }
    });

    router.get(props.path, query, { preserveState: true, preserveScroll: true, replace: true });
}

function onPeriod(): void {
    if (form.period !== 'custom') {
        apply();
    }
}

function setSegment(value: UsageSegmentKey): void {
    form.segment = value;
    apply();
}

const fieldClass =
    'w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 h-9 max-md:h-11';
</script>

<template>
    <form class="space-y-4 rounded-xl border border-border bg-card p-4" aria-label="Filtros" @submit.prevent="apply">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label class="space-y-1 text-sm">
                <span class="font-medium">Período</span>
                <select v-model="form.period" :class="fieldClass" @change="onPeriod">
                    <option v-for="period in PERIODS" :key="period.value" :value="period.value">{{ period.label }}</option>
                </select>
            </label>

            <label class="space-y-1 text-sm">
                <span class="font-medium">Plano</span>
                <select v-model="form.plan" :class="fieldClass" @change="apply">
                    <option value="">Todos</option>
                    <option v-for="plan in options.plans" :key="plan.key" :value="plan.key">{{ plan.label }}</option>
                </select>
            </label>

            <label class="space-y-1 text-sm">
                <span class="font-medium">Organização</span>
                <select v-model="form.organization" :class="fieldClass" @change="apply">
                    <option value="">Todas</option>
                    <option v-for="organization in options.organizations" :key="organization.ulid" :value="organization.ulid">
                        {{ organization.name }}{{ CLASSIFICATION_SUFFIX[organization.classification] }}
                    </option>
                </select>
            </label>
        </div>

        <div v-if="form.period === 'custom'" class="flex flex-wrap items-end gap-3">
            <label class="space-y-1 text-sm">
                <span class="font-medium">De</span>
                <input v-model="form.from" type="date" :class="fieldClass" />
            </label>
            <label class="space-y-1 text-sm">
                <span class="font-medium">Até</span>
                <input v-model="form.to" type="date" :class="fieldClass" />
            </label>
            <Button type="submit" class="max-md:min-h-11">Aplicar</Button>
        </div>

        <div class="space-y-2">
            <p class="text-sm font-medium">Segmento</p>
            <UsageSegmented :model-value="form.segment" :options="segmentOptions" label="Segmento" @update:model-value="setSegment" />
            <p class="text-xs text-muted-foreground">{{ segmentHelp }}</p>
        </div>

        <div v-if="errorMessages.length" role="alert" class="rounded-md border border-border bg-muted px-3 py-2 text-sm">
            <p v-for="message in errorMessages" :key="message">{{ message }}</p>
        </div>

        <p v-if="filters.adjusted_to_retention" class="text-xs text-muted-foreground">
            O início do período foi ajustado ao prazo de conservação dos dados.
        </p>
    </form>
</template>
