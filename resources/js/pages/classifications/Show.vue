<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { CircleAlert, PencilLine, RefreshCw, Send } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import StudentAvatar from '@/components/StudentAvatar.vue';

type Proposal = {
    value: string | null;
    state: 'resolved' | 'unconfigured' | 'no_result';
    is_percentage: boolean;
};
type Classification = {
    ulid: string;
    status: string;
    status_label: string;
    proposal: Proposal;
    proposed_value: string | null;
    final_value: string | null;
    effective_value: string | null;
    overridden: boolean;
    override_reason: string | null;
    can_confirm: boolean;
};
type Row = { name: string; photo_url: string | null; class_number: number | null; classification: Classification | null };

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string; has_profile: boolean };
    scope: 'period' | 'accumulated';
    periods: { ulid: string; label: string; selected: boolean }[];
    rows: Row[];
}>();

const page = usePage();
const selectedPeriod = computed(() => props.periods.find((period) => period.selected) ?? null);
const pending = computed(() => props.rows.filter((row) => row.classification?.can_confirm).length);
const confirmedCount = computed(() => props.rows.filter((row) => row.classification?.status === 'confirmed').length);

function basePath(periodUlid?: string): string {
    const period = periodUlid ?? selectedPeriod.value?.ulid ?? '';

    return `/classes/${props.schoolClass.ulid}/classifications/${period}`;
}

function selectScope(scope: 'period' | 'accumulated'): void {
    router.get(basePath(), { scope }, { preserveScroll: true });
}

// Value a teacher reads. "—" for a null (no computable result), never 0.
function grade(value: string | null): string {
    return value === null ? '—' : Number(value).toFixed(1);
}

// The proposal already comes translated to the profile's scale — a 4, a "Bom",
// an 80%. The "%" is only appended when the scale is itself a percentage.
function proposalLabel(proposal: Proposal): string {
    if (proposal.value === null) {
        return '—';
    }

    return proposal.is_percentage ? `${proposal.value}%` : proposal.value;
}

function selectPeriod(ulid: string): void {
    router.get(basePath(ulid), { scope: props.scope }, { preserveScroll: true });
}

const proposing = ref(false);
const publishing = ref(false);

function propose(): void {
    if (selectedPeriod.value === null) {
        return;
    }

    router.post(
        `${basePath()}/propose`,
        { scope: props.scope },
        {
            preserveScroll: true,
            onStart: () => (proposing.value = true),
            onFinish: () => (proposing.value = false),
        },
    );
}

function publish(): void {
    if (selectedPeriod.value === null || confirmedCount.value === 0) {
        return;
    }

    router.post(
        `${basePath()}/publish`,
        { scope: props.scope },
        {
            preserveScroll: true,
            onStart: () => (publishing.value = true),
            onFinish: () => (publishing.value = false),
        },
    );
}

// One override form at a time — which row's editor is open, if any.
const openUlid = ref<string | null>(null);
const confirmForm = useForm<{ final_value: string | null; override_reason: string }>({
    final_value: null,
    override_reason: '',
});

function accept(row: Row): void {
    if (row.classification === null) {
        return;
    }

    confirmForm.transform(() => ({ final_value: null, override_reason: '' }));
    confirmForm.post(`/classifications/${row.classification.ulid}/confirm`, { preserveScroll: true });
}

function openOverride(row: Row): void {
    if (row.classification === null) {
        return;
    }

    openUlid.value = row.classification.ulid;
    confirmForm.clearErrors();
    confirmForm.final_value = row.classification.proposed_value;
    confirmForm.override_reason = '';
}

function submitOverride(row: Row): void {
    if (row.classification === null) {
        return;
    }

    confirmForm.transform((data) => ({ ...data }));
    confirmForm.post(`/classifications/${row.classification.ulid}/confirm`, {
        preserveScroll: true,
        onSuccess: () => {
            openUlid.value = null;
        },
    });
}

const errorFor = computed(() => (page.props.errors as Record<string, string>)?.final_value ?? null);
</script>

<template>
    <Head :title="`Classificações — ${schoolClass.label}`" />

    <div class="space-y-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <Heading :title="`Classificações — ${schoolClass.label}`" :description="schoolClass.subject" />
                <Link :href="`/classes/${schoolClass.ulid}/results`" class="text-sm text-muted-foreground hover:underline">
                    ← Ver resultados
                </Link>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex gap-1 rounded-md bg-muted/40 p-0.5">
                    <button
                        type="button"
                        class="rounded px-3 py-1 text-sm"
                        :class="scope === 'period' ? 'bg-background font-medium shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                        @click="selectScope('period')"
                    >
                        Por período
                    </button>
                    <button
                        type="button"
                        class="rounded px-3 py-1 text-sm"
                        :class="scope === 'accumulated' ? 'bg-background font-medium shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                        @click="selectScope('accumulated')"
                    >
                        Acumulado
                    </button>
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
        </div>

        <p v-if="!schoolClass.has_profile" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Esta turma não tem perfil de avaliação associado, por isso não há classificações a propor.
        </p>

        <template v-else>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-muted-foreground">
                    <template v-if="scope === 'accumulated'">
                        Acumulado: reprocessa todos os elementos válidos do ano até este período (não é a média dos períodos).
                    </template>
                    <template v-else>O sistema propõe; o professor confirma.</template>
                    {{ pending }} por confirmar.
                </p>
                <div class="flex gap-2">
                    <button
                        v-if="confirmedCount > 0"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-md border border-emerald-600 px-4 py-2 text-sm font-medium text-emerald-700 transition-colors hover:bg-emerald-50 disabled:opacity-50 dark:text-emerald-400 dark:hover:bg-emerald-950"
                        :disabled="publishing"
                        @click="publish"
                    >
                        <Send class="size-4" />
                        Publicar confirmadas ({{ confirmedCount }})
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
                        :disabled="selectedPeriod === null || proposing"
                        @click="propose"
                    >
                        <RefreshCw class="size-4" :class="{ 'animate-spin': proposing }" />
                        Gerar / atualizar propostas
                    </button>
                </div>
            </div>

            <!-- An accept/confirm that fails (stale proposal, already confirmed)
                 comes back with an error but no editor open — show it here so the
                 click is never silently swallowed. -->
            <p
                v-if="errorFor && openUlid === null"
                class="rounded-md border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
            >
                {{ errorFor }}
            </p>

            <div v-if="rows.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
                <p class="text-sm text-muted-foreground">Sem alunos neste período.</p>
            </div>

            <div v-else class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2 font-medium">Aluno</th>
                            <th class="px-3 py-2 text-center font-medium">Estado</th>
                            <th class="px-3 py-2 text-right font-medium">Proposta</th>
                            <th class="px-3 py-2 text-right font-medium">Final</th>
                            <th class="px-3 py-2 text-right font-medium">Ação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template v-for="row in rows" :key="row.name">
                            <tr class="hover:bg-muted/20">
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-muted-foreground">{{ row.class_number ?? '—' }}</span>
                                        <StudentAvatar :photo-url="row.photo_url" size="xs" />
                                        <span class="font-medium">{{ row.name }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <span
                                        v-if="row.classification"
                                        class="rounded-full px-2 py-0.5 text-xs"
                                        :class="{
                                            'bg-muted text-muted-foreground': row.classification.status === 'proposed',
                                            'bg-emerald-100 text-emerald-800': row.classification.status === 'confirmed' || row.classification.status === 'published',
                                        }"
                                    >{{ row.classification.status_label }}</span>
                                    <span v-else class="text-xs text-muted-foreground">Sem proposta</span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    <span v-if="row.classification" :class="{ 'text-muted-foreground': row.classification.proposal.value === null }">
                                        {{ proposalLabel(row.classification.proposal) }}
                                    </span>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums">
                                    <template v-if="row.classification && row.classification.status !== 'proposed'">
                                        {{ grade(row.classification.final_value) }}
                                        <CircleAlert
                                            v-if="row.classification.overridden"
                                            class="ml-0.5 inline size-3 text-amber-500"
                                            :title="`Alterado: ${row.classification.override_reason}`"
                                        />
                                    </template>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <div v-if="row.classification?.can_confirm" class="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            class="rounded-md border border-border px-2.5 py-1 text-xs hover:bg-muted/40"
                                            @click="openOverride(row)"
                                        >
                                            <PencilLine class="mr-1 inline size-3" />Alterar
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-md bg-primary px-2.5 py-1 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                                            :disabled="confirmForm.processing"
                                            @click="accept(row)"
                                        >
                                            Confirmar
                                        </button>
                                    </div>
                                    <span v-else-if="row.classification" class="text-xs text-muted-foreground">✓</span>
                                </td>
                            </tr>
                            <tr v-if="row.classification && openUlid === row.classification.ulid" class="bg-muted/20">
                                <td colspan="5" class="px-3 py-3">
                                    <div class="flex flex-wrap items-end gap-3">
                                        <label class="text-sm">
                                            <span class="mb-1 block text-xs text-muted-foreground">Valor final</span>
                                            <input
                                                v-model="confirmForm.final_value"
                                                type="number"
                                                step="0.001"
                                                class="w-28 rounded-md border border-border bg-background px-2 py-1 tabular-nums"
                                            />
                                        </label>
                                        <label class="flex-1 text-sm">
                                            <span class="mb-1 block text-xs text-muted-foreground">Motivo (obrigatório se alterar a proposta)</span>
                                            <input
                                                v-model="confirmForm.override_reason"
                                                type="text"
                                                maxlength="1000"
                                                class="w-full rounded-md border border-border bg-background px-2 py-1"
                                                placeholder="Ex.: participação sustentada não refletida nos instrumentos"
                                            />
                                        </label>
                                        <div class="flex gap-2">
                                            <button
                                                type="button"
                                                class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                                                @click="openUlid = null"
                                            >
                                                Cancelar
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                                                :disabled="confirmForm.processing"
                                                @click="submitOverride(row)"
                                            >
                                                Confirmar
                                            </button>
                                        </div>
                                    </div>
                                    <p v-if="errorFor" class="mt-2 text-xs text-red-600">{{ errorFor }}</p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <p class="flex items-start gap-2 text-xs text-muted-foreground">
            <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
            A proposta é o valor determinístico calculado pelo motor. Confirmar aceita-o; alterá-lo exige um motivo,
            e guarda a proposta original, o valor final, o motivo, o autor e a data. A confirmação congela um registo
            de como o valor foi obtido. "—" significa sem elementos, nunca zero.
        </p>
    </div>
</template>
