<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { FileText, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';

type Review = { ulid: string; reviewed_on: string; effectiveness: string | null; effectiveness_label: string | null; notes: string | null };
type Intervention = {
    ulid: string;
    title: string;
    description: string | null;
    student: string;
    domain: string | null;
    status: string;
    status_label: string;
    is_closed: boolean;
    started_on: string;
    expected_end_on: string | null;
    concluded_on: string | null;
    include_in_report: boolean;
    reviews: Review[];
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string };
    enrollments: { id: number; name: string }[];
    domains: { id: number; name: string }[];
    effectivenessOptions: { value: string; label: string }[];
    interventions: Intervention[];
}>();

const today = new Date().toISOString().slice(0, 10);

const createForm = useForm<{
    enrollment_id: number | null;
    domain_id: number | null;
    title: string;
    description: string;
    started_on: string;
    expected_end_on: string | null;
    include_in_report: boolean;
}>({
    enrollment_id: props.enrollments[0]?.id ?? null,
    domain_id: null,
    title: '',
    description: '',
    started_on: today,
    expected_end_on: null,
    include_in_report: false,
});

function create(): void {
    createForm.post(`/classes/${props.schoolClass.ulid}/interventions`, {
        preserveScroll: true,
        onSuccess: () => createForm.reset('title', 'description', 'domain_id', 'expected_end_on', 'include_in_report'),
    });
}

function setStatus(intervention: Intervention, status: string): void {
    router.patch(`/interventions/${intervention.ulid}`, { status }, { preserveScroll: true });
}

function remove(intervention: Intervention): void {
    router.delete(`/interventions/${intervention.ulid}`, { preserveScroll: true });
}

// One review form open at a time.
const openReview = ref<string | null>(null);
const reviewForm = useForm<{ reviewed_on: string; effectiveness: string | null; notes: string }>({
    reviewed_on: today,
    effectiveness: null,
    notes: '',
});

function openReviewFor(intervention: Intervention): void {
    openReview.value = intervention.ulid;
    reviewForm.reset();
    reviewForm.reviewed_on = today;
}

function submitReview(intervention: Intervention): void {
    reviewForm.post(`/interventions/${intervention.ulid}/reviews`, {
        preserveScroll: true,
        onSuccess: () => (openReview.value = null),
    });
}

function when(date: string): string {
    return new Date(date).toLocaleDateString('pt-PT', { day: '2-digit', month: 'short', year: 'numeric' });
}

const statusClasses: Record<string, string> = {
    new: 'bg-muted text-muted-foreground',
    in_progress: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    concluded: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    cancelled: 'bg-muted text-muted-foreground line-through',
};
</script>

<template>
    <Head :title="`Intervenções — ${schoolClass.label}`" />

    <div class="mx-auto w-full max-w-3xl space-y-5 p-4">
        <div>
            <Heading :title="`Intervenções — ${schoolClass.label}`" :description="schoolClass.subject" />
            <Link href="/interventions" class="text-sm text-muted-foreground hover:underline">← Todas as turmas</Link>
        </div>

        <form class="space-y-3 rounded-lg border border-border p-4" @submit.prevent="create">
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Aluno</span>
                    <select v-model="createForm.enrollment_id" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                        <option v-for="enrollment in enrollments" :key="enrollment.id" :value="enrollment.id">{{ enrollment.name }}</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Domínio (opcional)</span>
                    <select v-model="createForm.domain_id" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                        <option :value="null">—</option>
                        <option v-for="domain in domains" :key="domain.id" :value="domain.id">{{ domain.name }}</option>
                    </select>
                </label>
            </div>
            <label class="block text-sm">
                <span class="mb-1 block text-xs text-muted-foreground">Título</span>
                <input v-model="createForm.title" type="text" maxlength="200" class="w-full rounded-md border border-border bg-background px-3 py-1.5" placeholder="Ex.: Apoio à leitura em pequeno grupo" />
            </label>
            <p v-if="createForm.errors.title" class="text-xs text-red-600">{{ createForm.errors.title }}</p>
            <label class="block text-sm">
                <span class="mb-1 block text-xs text-muted-foreground">Descrição (opcional)</span>
                <textarea v-model="createForm.description" rows="2" maxlength="5000" class="w-full rounded-md border border-border bg-background px-3 py-2" placeholder="Medida ou ação aplicada…"></textarea>
            </label>
            <div class="flex flex-wrap items-end gap-3">
                <label class="text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Início</span>
                    <input v-model="createForm.started_on" type="date" class="rounded-md border border-border bg-background px-2 py-1.5" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Fim previsto</span>
                    <input v-model="createForm.expected_end_on" type="date" class="rounded-md border border-border bg-background px-2 py-1.5" />
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="createForm.include_in_report" type="checkbox" class="rounded border-border" /> Incluir no relatório
                </label>
                <button type="submit" class="ml-auto inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50" :disabled="createForm.processing">
                    <Plus class="size-4" /> Criar intervenção
                </button>
            </div>
        </form>

        <div v-if="interventions.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <p class="text-sm text-muted-foreground">Sem intervenções nesta turma.</p>
        </div>

        <div v-for="intervention in interventions" :key="intervention.ulid" class="space-y-3 rounded-lg border border-border p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-2 py-0.5 text-xs" :class="statusClasses[intervention.status]">{{ intervention.status_label }}</span>
                        <span class="font-medium">{{ intervention.title }}</span>
                        <FileText v-if="intervention.include_in_report" class="size-3.5 text-emerald-500" title="Incluído no relatório" />
                    </div>
                    <div class="mt-0.5 text-sm text-muted-foreground">
                        {{ intervention.student }}<span v-if="intervention.domain"> · {{ intervention.domain }}</span>
                    </div>
                </div>
                <button type="button" class="rounded-md p-1.5 text-muted-foreground hover:bg-muted/40 hover:text-red-600" title="Remover" @click="remove(intervention)">
                    <Trash2 class="size-4" />
                </button>
            </div>

            <p v-if="intervention.description" class="text-sm text-muted-foreground">{{ intervention.description }}</p>

            <div class="text-xs text-muted-foreground">
                Início {{ when(intervention.started_on) }}
                <span v-if="intervention.expected_end_on"> · Fim previsto {{ when(intervention.expected_end_on) }}</span>
                <span v-if="intervention.concluded_on"> · Concluída {{ when(intervention.concluded_on) }}</span>
            </div>

            <div v-if="!intervention.is_closed" class="flex flex-wrap gap-2 text-xs">
                <button v-if="intervention.status === 'new'" type="button" class="rounded-md border border-border px-2.5 py-1 hover:bg-muted/40" @click="setStatus(intervention, 'in_progress')">Marcar em curso</button>
                <button type="button" class="rounded-md border border-emerald-600 px-2.5 py-1 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950" @click="setStatus(intervention, 'concluded')">Concluir</button>
                <button type="button" class="rounded-md border border-border px-2.5 py-1 text-muted-foreground hover:bg-muted/40" @click="setStatus(intervention, 'cancelled')">Cancelar</button>
            </div>

            <div class="border-t border-border pt-3">
                <div v-if="intervention.reviews.length" class="mb-2 space-y-1.5">
                    <div v-for="review in intervention.reviews" :key="review.ulid" class="text-sm">
                        <span class="text-xs text-muted-foreground tabular-nums">{{ when(review.reviewed_on) }}</span>
                        <span v-if="review.effectiveness_label" class="ml-2 rounded-full bg-accent px-2 py-0.5 text-xs text-accent-foreground">{{ review.effectiveness_label }}</span>
                        <p v-if="review.notes" class="text-muted-foreground">{{ review.notes }}</p>
                    </div>
                </div>

                <div v-if="openReview === intervention.ulid" class="flex flex-wrap items-end gap-2">
                    <label class="text-sm">
                        <span class="mb-1 block text-xs text-muted-foreground">Data</span>
                        <input v-model="reviewForm.reviewed_on" type="date" class="rounded-md border border-border bg-background px-2 py-1" />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-xs text-muted-foreground">Eficácia</span>
                        <select v-model="reviewForm.effectiveness" class="rounded-md border border-border bg-background px-2 py-1">
                            <option :value="null">—</option>
                            <option v-for="option in effectivenessOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                    </label>
                    <input v-model="reviewForm.notes" type="text" maxlength="2000" class="min-w-40 flex-1 rounded-md border border-border bg-background px-2 py-1 text-sm" placeholder="Notas…" />
                    <button type="button" class="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:opacity-90" @click="submitReview(intervention)">Guardar</button>
                    <button type="button" class="rounded-md px-2 py-1.5 text-sm text-muted-foreground hover:underline" @click="openReview = null">Cancelar</button>
                </div>
                <button v-else type="button" class="text-xs text-primary hover:underline" @click="openReviewFor(intervention)">+ Apreciação</button>
            </div>
        </div>
    </div>
</template>
