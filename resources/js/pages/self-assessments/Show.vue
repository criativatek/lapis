<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import SelfAssessmentCriteriaPanel from '@/components/SelfAssessmentCriteriaPanel.vue';
import SelfAssessmentReadOnlyNotice from '@/components/SelfAssessmentReadOnlyNotice.vue';
import { statusToneClasses } from '@/lib/statusTone';
import type { SelfAssessmentConfiguration, SelfAssessmentReflectionQuestion } from '@/types/self-assessment';

type Row = {
    enrollment_ulid: string;
    name: string;
    class_number: number | null;
    status: string | null;
    status_label: string | null;
    /** The student also has an answer for this period under another (earlier) set of criteria. */
    has_earlier_round?: boolean;
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string };
    periods: { ulid: string; label: string; selected: boolean }[];
    rows: Row[];
    configuration: SelfAssessmentConfiguration;
    reflectionQuestions?: SelfAssessmentReflectionQuestion[];
}>();

const selectedPeriod = () => props.periods.find((period) => period.selected) ?? null;

// The entitlement decides whether the entry point exists at all. Hiding it is
// presentation only — the route itself is gated by `module:self_assessment_links`
// on the server, so this is convenience rather than access control (same
// pattern as assessments/Index.vue's canImportGrids).
const canSeeLinks = computed(() => usePage().props.modules.includes('self_assessment_links'));

function selectPeriod(ulid: string): void {
    router.get(`/classes/${props.schoolClass.ulid}/self-assessments/${ulid}`, {}, { preserveScroll: true });
}

function open(row: Row): void {
    const period = selectedPeriod();

    if (period === null) {
        return;
    }

    router.get(`/classes/${props.schoolClass.ulid}/self-assessments/${period.ulid}/${row.enrollment_ulid}`);
}

// STARTING A NEW SELF-ASSESSMENT WITH THE CLASS'S CURRENT CRITERIA. Asked in
// line, not with window.confirm: the consequence is a paragraph long, and it has
// to be read, not dismissed.
const confirmingRenew = ref(false);
const renewing = ref(false);
const renewError = ref<string | null>(null);

function renew(): void {
    renewing.value = true;
    renewError.value = null;

    router.post(
        `/classes/${props.schoolClass.ulid}/self-assessments/renew`,
        { period: selectedPeriod()?.ulid ?? null },
        {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                renewError.value = errors.renew ?? 'Não foi possível iniciar uma nova autoavaliação.';
            },
            onSuccess: () => {
                confirmingRenew.value = false;
            },
            onFinish: () => {
                renewing.value = false;
            },
        },
    );
}

// THE REFLECTION QUESTIONS are the only part of the form a teacher words. One
// that already has student answers stays read-only: rewording it would change
// what was answered.
const reflectionPrompts = ref<Record<number, string>>({});
const reflectionErrors = ref<Record<string, string>>({});
const savingReflections = ref(false);

watch(
    () => props.reflectionQuestions,
    (questions) => {
        reflectionPrompts.value = Object.fromEntries((questions ?? []).map((question) => [question.id, question.prompt]));
    },
    { immediate: true },
);

const editableReflections = computed(() => (props.reflectionQuestions ?? []).filter((question) => !question.answered));

function saveReflections(): void {
    savingReflections.value = true;
    reflectionErrors.value = {};

    // Only the editable ones travel: sending an answered question would be
    // refused as a whole, and nothing of it would be saved.
    const prompts = Object.fromEntries(editableReflections.value.map((question) => [question.id, reflectionPrompts.value[question.id] ?? '']));

    router.put(
        `/classes/${props.schoolClass.ulid}/self-assessments/questions`,
        { prompts },
        {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                reflectionErrors.value = errors;
            },
            onFinish: () => {
                savingReflections.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`Autoavaliações — ${schoolClass.label}`" />

    <div class="mx-auto w-full max-w-2xl space-y-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <Heading :title="`Autoavaliações — ${schoolClass.label}`" :description="schoolClass.subject" />
                <Link href="/self-assessments" class="inline-flex min-h-11 items-center text-sm text-muted-foreground hover:underline">← Todas as turmas</Link>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div v-if="periods.length" class="flex flex-wrap gap-1">
                    <button
                        v-for="period in periods"
                        :key="period.ulid"
                        type="button"
                        class="min-h-11 rounded-md border px-3 py-1.5 text-sm"
                        :class="period.selected ? 'border-primary bg-primary text-primary-foreground' : 'border-border hover:bg-muted/40'"
                        @click="selectPeriod(period.ulid)"
                    >
                        {{ period.label }}
                    </button>
                </div>
                <Link
                    v-if="canSeeLinks && selectedPeriod()"
                    :href="`/classes/${schoolClass.ulid}/self-assessments/${selectedPeriod()?.ulid}/links`"
                    class="inline-flex min-h-11 items-center rounded-md border border-border px-3 py-1.5 text-sm text-muted-foreground hover:bg-muted/40"
                >
                    Ligações para os alunos
                </Link>
            </div>
        </div>

        <SelfAssessmentReadOnlyNotice v-if="configuration.read_only" audience="teacher" />

        <SelfAssessmentCriteriaPanel :configuration="configuration" context="list">
            <template #renew>
                <div v-if="configuration.can_renew" class="pt-1">
                    <button
                        v-if="!confirmingRenew"
                        type="button"
                        class="min-h-11 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90"
                        data-test="renew-start"
                        @click="confirmingRenew = true"
                    >
                        Iniciar nova autoavaliação com os critérios atuais
                    </button>

                    <div v-else class="space-y-3 rounded-md border border-border bg-background p-3 text-foreground" data-test="renew-confirmation">
                        <p>
                            {{ configuration.saved_count === 1 ? 'A autoavaliação já guardada fica' : `As ${configuration.saved_count} autoavaliações já guardadas ficam` }}
                            como {{ configuration.saved_count === 1 ? 'foi preenchida' : 'foram preenchidas' }}, com os critérios anteriores, e
                            {{ configuration.saved_count === 1 ? 'continua visível' : 'continuam visíveis' }}. As novas respostas usam os critérios atuais da turma.
                        </p>
                        <p v-if="renewError" role="alert" class="text-destructive" data-test="renew-error">{{ renewError }}</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="min-h-11 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                                :disabled="renewing"
                                data-test="renew-confirm"
                                @click="renew"
                            >
                                Iniciar nova autoavaliação
                            </button>
                            <button type="button" class="min-h-11 rounded-md border border-border px-4 py-2 text-sm hover:bg-muted/40" data-test="renew-cancel" @click="confirmingRenew = false">
                                Cancelar
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </SelfAssessmentCriteriaPanel>

        <ul class="divide-y divide-border overflow-hidden rounded-lg border border-border">
            <li v-for="row in rows" :key="row.enrollment_ulid">
                <button type="button" class="flex min-h-11 w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-muted/30" @click="open(row)">
                    <span>
                        <span class="text-muted-foreground">{{ row.class_number ?? '—' }}</span>
                        <span class="ml-2 font-medium">{{ row.name }}</span>
                        <span v-if="row.has_earlier_round" class="mt-0.5 block text-xs text-muted-foreground" data-test="earlier-round-tag">Também tem uma resposta anterior</span>
                    </span>
                    <span class="flex items-center gap-3">
                        <span
                            v-if="row.status"
                            class="rounded-full px-2 py-0.5 text-xs"
                            :class="statusToneClasses(row.status ?? '')"
                        >{{ row.status_label }}</span>
                        <span v-else class="text-xs text-muted-foreground">Por registar</span>
                        <ChevronRight class="size-4 text-muted-foreground" />
                    </span>
                </button>
            </li>
        </ul>

        <details v-if="configuration.can_edit && reflectionQuestions?.length" class="rounded-lg border border-border" data-test="reflection-editor">
            <summary class="flex min-h-11 cursor-pointer items-center px-4 text-sm font-semibold">Perguntas de reflexão</summary>

            <form class="space-y-4 border-t border-border p-4 text-sm" @submit.prevent="saveReflections">
                <p class="text-muted-foreground">Só as perguntas de reflexão podem ser personalizadas. Os domínios, as ponderações e a escala vêm dos critérios da turma.</p>

                <div v-for="question in reflectionQuestions" :key="question.id">
                    <p v-if="question.answered" class="mb-1 font-medium">{{ question.role_label }}</p>
                    <label v-else :for="`reflection-${question.id}`" class="mb-1 block font-medium">{{ question.role_label }}</label>

                    <p v-if="question.answered" class="rounded-md bg-muted/40 px-3 py-2">{{ question.prompt }}</p>
                    <textarea
                        v-else
                        :id="`reflection-${question.id}`"
                        v-model="reflectionPrompts[question.id]"
                        rows="2"
                        maxlength="500"
                        class="min-h-11 w-full rounded-md border border-border bg-background px-3 py-2"
                        :aria-invalid="reflectionErrors[`prompts.${question.id}`] ? 'true' : undefined"
                    ></textarea>

                    <p v-if="question.answered" class="mt-1 text-xs text-muted-foreground">Já tem respostas — a redação mantém-se.</p>
                    <p v-if="reflectionErrors[`prompts.${question.id}`]" role="alert" class="mt-1 text-xs text-destructive">{{ reflectionErrors[`prompts.${question.id}`] }}</p>
                </div>

                <p v-if="reflectionErrors.prompts" role="alert" class="text-xs text-destructive">{{ reflectionErrors.prompts }}</p>

                <button
                    v-if="editableReflections.length"
                    type="submit"
                    class="min-h-11 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                    :disabled="savingReflections"
                >
                    Guardar perguntas
                </button>
            </form>
        </details>
    </div>
</template>
