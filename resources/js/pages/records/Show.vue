<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';

type Record = {
    ulid: string;
    kind: string;
    kind_label: string;
    disciplinary_severity_label: string | null;
    description: string;
    student: string | null;
    domain: string | null;
    occurred_at: string;
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string };
    enrollments: { id: number; name: string }[];
    domains: { id: number; name: string }[];
    kinds: { value: string; label: string }[];
    severities: { value: string; label: string }[];
    records: Record[];
}>();

// Today, in the yyyy-mm-dd shape a date input expects.
const today = new Date().toISOString().slice(0, 10);

const form = useForm<{
    kind: string;
    disciplinary_severity: string | null;
    description: string;
    occurred_at: string;
    enrollment_id: number | null;
    domain_id: number | null;
}>({
    kind: 'note',
    disciplinary_severity: null,
    description: '',
    occurred_at: today,
    enrollment_id: null,
    domain_id: null,
});

function submit(): void {
    form.post(`/classes/${props.schoolClass.ulid}/records`, {
        preserveScroll: true,
        onSuccess: () => form.reset('description', 'enrollment_id', 'domain_id', 'disciplinary_severity'),
    });
}

function remove(record: Record): void {
    router.delete(`/records/${record.ulid}`, { preserveScroll: true });
}

function when(iso: string): string {
    return new Date(iso).toLocaleDateString('pt-PT', { day: '2-digit', month: 'short', year: 'numeric' });
}

const hasRecords = computed(() => props.records.length > 0);
</script>

<template>
    <Head :title="`Registos — ${schoolClass.label}`" />

    <div class="mx-auto w-full max-w-3xl space-y-5 p-4">
        <div>
            <Heading :title="`Registos — ${schoolClass.label}`" :description="schoolClass.subject" />
            <Link href="/records" class="text-sm text-muted-foreground hover:underline">← Todas as turmas</Link>
        </div>

        <form class="space-y-3 rounded-lg border border-border p-4" @submit.prevent="submit">
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Tipo</span>
                    <select v-model="form.kind" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                        <option v-for="kind in kinds" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Data</span>
                    <input v-model="form.occurred_at" type="date" class="w-full rounded-md border border-border bg-background px-2 py-1.5" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Aluno</span>
                    <select v-model="form.enrollment_id" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                        <option :value="null">Turma inteira</option>
                        <option v-for="enrollment in enrollments" :key="enrollment.id" :value="enrollment.id">{{ enrollment.name }}</option>
                    </select>
                </label>
            </div>

            <label v-if="form.kind === 'incident'" class="block text-sm">
                <span class="mb-1 block text-xs text-muted-foreground">Gravidade</span>
                <select v-model="form.disciplinary_severity" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                    <option :value="null" disabled>Escolher…</option>
                    <option v-for="severity in severities" :key="severity.value" :value="severity.value">{{ severity.label }}</option>
                </select>
                <p v-if="form.errors.disciplinary_severity" class="mt-1 text-xs text-red-600">{{ form.errors.disciplinary_severity }}</p>
            </label>

            <label class="block text-sm">
                <span class="mb-1 block text-xs text-muted-foreground">Descrição</span>
                <textarea
                    v-model="form.description"
                    rows="2"
                    maxlength="1000"
                    class="w-full rounded-md border border-border bg-background px-3 py-2"
                    placeholder="O que observou…"
                ></textarea>
            </label>
            <p v-if="form.errors.description" class="text-xs text-red-600">{{ form.errors.description }}</p>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <label v-if="domains.length" class="text-sm">
                    <span class="mr-2 text-xs text-muted-foreground">Domínio</span>
                    <select v-model="form.domain_id" class="rounded-md border border-border bg-background px-2 py-1.5">
                        <option :value="null">—</option>
                        <option v-for="domain in domains" :key="domain.id" :value="domain.id">{{ domain.name }}</option>
                    </select>
                </label>
                <button
                    type="submit"
                    class="ml-auto rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Adicionar registo
                </button>
            </div>
        </form>

        <div v-if="!hasRecords" class="rounded-lg border border-dashed border-border p-10 text-center">
            <p class="text-sm text-muted-foreground">Ainda não há registos nesta turma.</p>
        </div>

        <ul v-else class="space-y-2">
            <li v-for="record in records" :key="record.ulid" class="flex items-start gap-3 rounded-lg border border-border p-3">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-accent px-2 py-0.5 text-xs text-accent-foreground">{{ record.kind_label }}</span>
                        <span v-if="record.disciplinary_severity_label" class="rounded-full bg-destructive/10 px-2 py-0.5 text-xs text-destructive">{{ record.disciplinary_severity_label }}</span>
                        <span class="text-sm font-medium">{{ record.student ?? 'Turma inteira' }}</span>
                        <span v-if="record.domain" class="text-xs text-muted-foreground">· {{ record.domain }}</span>
                        <span class="ml-auto text-xs text-muted-foreground tabular-nums">{{ when(record.occurred_at) }}</span>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">{{ record.description }}</p>
                </div>
                <button
                    type="button"
                    class="shrink-0 rounded-md p-1.5 text-muted-foreground hover:bg-muted/40 hover:text-red-600"
                    title="Remover"
                    @click="remove(record)"
                >
                    <Trash2 class="size-4" />
                </button>
            </li>
        </ul>
    </div>
</template>
