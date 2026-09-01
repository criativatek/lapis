<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { MessageSquarePlus } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';

type HomeworkStatus = 'done' | 'partially_done' | 'not_done';
type HomeworkRow = {
    enrollment_id: number;
    name: string;
    homework_status: HomeworkStatus | null;
    description: string;
    observationOpen: boolean;
    descriptionCustomized: boolean;
};

const props = defineProps<{
    classUlid: string;
    occurredAt: string;
}>();

const emit = defineEmits<{
    saved: [];
}>();

const statuses: { value: HomeworkStatus; label: string; symbol: string; bulkLabel: string }[] = [
    { value: 'done', label: 'Realizado', symbol: '✓', bulkLabel: 'Todos realizados' },
    { value: 'partially_done', label: 'Parcialmente realizado', symbol: '◐', bulkLabel: 'Todos parciais' },
    { value: 'not_done', label: 'Não realizado', symbol: '✕', bulkLabel: 'Todos não realizados' },
];

const rows = ref<HomeworkRow[]>([]);
const sharedDescription = ref('');
const loading = ref(false);
const loadError = ref('');
const batchHasRecords = ref(false);
let loadSequence = 0;

const form = useForm<{
    occurred_at: string;
    rows: { enrollment_id: number; homework_status: HomeworkStatus | null; description: string }[];
}>({ occurred_at: props.occurredAt, rows: [] });

const counts = computed(() => ({
    done: rows.value.filter((row) => row.homework_status === 'done').length,
    partially_done: rows.value.filter((row) => row.homework_status === 'partially_done').length,
    not_done: rows.value.filter((row) => row.homework_status === 'not_done').length,
    empty: rows.value.filter((row) => row.homework_status === null).length,
}));

async function loadBatch(): Promise<void> {
    const sequence = ++loadSequence;
    batchHasRecords.value = false;
    rows.value = [];
    loadError.value = '';

    if (props.occurredAt === '') {
        loading.value = false;

        return;
    }

    loading.value = true;
    sharedDescription.value = '';

    try {
        const response = await fetch(
            `/classes/${props.classUlid}/records/homework-batch?occurred_at=${encodeURIComponent(props.occurredAt)}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            throw new Error('load failed');
        }

        const payload = await response.json() as {
            enrollments: { id: number; name: string; homework_status: HomeworkStatus | null; description: string }[];
        };

        if (sequence !== loadSequence) {
            return;
        }

        rows.value = payload.enrollments.map((enrollment) => ({
            enrollment_id: enrollment.id,
            name: enrollment.name,
            homework_status: enrollment.homework_status,
            description: enrollment.description,
            observationOpen: enrollment.description !== '',
            descriptionCustomized: enrollment.description !== '',
        }));
        batchHasRecords.value = payload.enrollments.some((enrollment) => enrollment.homework_status !== null);
    } catch {
        if (sequence === loadSequence) {
            rows.value = [];
            loadError.value = 'Não foi possível carregar a grelha. Tente novamente.';
        }
    } finally {
        if (sequence === loadSequence) {
            loading.value = false;
        }
    }
}

// Loads once the grid is actually in the browser — `onMounted` never runs
// during SSR (unlike `setup()`), and this fetches over the network with a
// relative URL that has no origin to resolve against on the server.
onMounted(() => {
    void loadBatch();
});

watch(() => props.occurredAt, loadBatch);

function setStatus(row: HomeworkRow, status: HomeworkStatus): void {
    row.homework_status = row.homework_status === status ? null : status;
}

function setAll(status: HomeworkStatus | null): void {
    for (const row of rows.value) {
        row.homework_status = status;
    }
}

function applySharedDescription(): void {
    for (const row of rows.value) {
        if (!row.descriptionCustomized) {
            row.description = sharedDescription.value;
        }
    }
}

function customizeDescription(row: HomeworkRow): void {
    row.descriptionCustomized = true;
}

function submit(): void {
    form.occurred_at = props.occurredAt;
    form.rows = rows.value.map((row) => ({
        enrollment_id: row.enrollment_id,
        homework_status: row.homework_status,
        description: row.homework_status === null ? '' : row.description,
    }));

    form.put(`/classes/${props.classUlid}/records/homework-batch`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('saved');
            void loadBatch();
        },
    });
}

function destroyBatch(): void {
    if (!confirm('Eliminar todo o trabalho de casa desta data?')) {
        return;
    }

    router.delete(`/classes/${props.classUlid}/records/homework-batch`, {
        data: { occurred_at: props.occurredAt },
        preserveScroll: true,
        onSuccess: () => {
            emit('saved');
            void loadBatch();
        },
    });
}
</script>

<template>
    <section class="space-y-3" aria-label="Grelha de trabalho de casa">
        <label class="block text-sm">
            <span class="mb-1 block text-xs text-muted-foreground">Descrição comum</span>
            <input
                v-model="sharedDescription"
                maxlength="1000"
                class="w-full rounded-md border border-border bg-background px-3 py-2"
                placeholder="Ex.: Exercícios 1 a 5 da página 42"
                :disabled="loading"
                @input="applySharedDescription"
            />
            <p class="mt-1 text-xs text-muted-foreground">
                Preenche a Observação de cada aluno como ponto de partida; pode personalizá-la em cada linha.
            </p>
        </label>

        <div class="flex flex-wrap gap-1.5" aria-label="Ações em todos os alunos">
            <button v-for="status in statuses" :key="status.value" type="button" class="rounded-md border border-border px-2 py-1.5 text-xs hover:bg-muted/40" @click="setAll(status.value)">
                {{ status.bulkLabel }}
            </button>
            <button type="button" class="rounded-md border border-border px-2 py-1.5 text-xs text-muted-foreground hover:bg-muted/40" @click="setAll(null)">Limpar</button>
        </div>

        <div class="flex flex-wrap gap-1.5 text-xs tabular-nums" aria-live="polite">
            <span class="inline-flex items-center gap-1 rounded-full bg-muted/60 px-2.5 py-1">
                <span aria-hidden="true">✓</span> Realizados {{ counts.done }}
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-muted/60 px-2.5 py-1">
                <span aria-hidden="true">◐</span> Parciais {{ counts.partially_done }}
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-muted/60 px-2.5 py-1">
                <span aria-hidden="true">✕</span> Não realizados {{ counts.not_done }}
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-muted/60 px-2.5 py-1 text-muted-foreground">
                <span aria-hidden="true">—</span> Sem informação {{ counts.empty }}
            </span>
        </div>

        <p v-if="loading" class="py-8 text-center text-sm text-muted-foreground">A carregar alunos…</p>
        <p v-else-if="loadError" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ loadError }}</p>
        <p v-else-if="rows.length === 0" class="py-8 text-center text-sm text-muted-foreground">Esta turma não tem alunos ativos.</p>

        <div v-else class="space-y-2 lg:space-y-0 lg:overflow-hidden lg:rounded-md lg:border lg:border-border">
            <div class="hidden grid-cols-[minmax(10rem,1fr)_repeat(3,8rem)_7rem] gap-1 bg-muted/30 px-3 py-2 text-xs text-muted-foreground lg:grid">
                <span>Aluno</span><span class="text-center">Realizado</span><span class="text-center">Parcialmente realizado</span><span class="text-center">Não realizado</span><span></span>
            </div>
            <div v-for="row in rows" :key="row.enrollment_id" class="rounded-md border border-border p-3 lg:rounded-none lg:border-x-0 lg:border-b-0 lg:p-0">
                <div class="grid gap-2 lg:grid-cols-[minmax(10rem,1fr)_repeat(3,8rem)_7rem] lg:items-center lg:px-3 lg:py-2">
                    <span class="text-sm font-medium">{{ row.name }}</span>
                    <button
                        v-for="status in statuses"
                        :key="status.value"
                        type="button"
                        class="rounded-md border px-2 py-2 text-xs sm:py-1.5"
                        :class="row.homework_status === status.value ? 'border-primary bg-primary text-primary-foreground' : 'border-border hover:bg-muted/40'"
                        :aria-pressed="row.homework_status === status.value"
                        :aria-label="`${status.label} — ${row.name}`"
                        @click="setStatus(row, status.value)"
                    >
                        <span class="lg:hidden">{{ status.label }}</span><span class="hidden lg:inline" aria-hidden="true">{{ status.symbol }}</span>
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-left text-xs font-medium text-primary hover:underline lg:justify-center lg:text-center"
                        :aria-expanded="row.observationOpen"
                        @click="row.observationOpen = !row.observationOpen"
                    >
                        <MessageSquarePlus class="size-3.5 shrink-0" aria-hidden="true" />
                        {{ row.observationOpen ? 'Fechar Observação' : 'Observação' }}
                    </button>
                </div>
                <div v-if="row.observationOpen" class="border-t border-border bg-muted/10 p-3">
                    <label class="block text-xs text-muted-foreground">
                        Observação — {{ row.name }}
                        <textarea
                            v-model="row.description"
                            rows="2"
                            maxlength="1000"
                            class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="row.homework_status === null"
                            :placeholder="row.homework_status === null ? 'Escolha primeiro um estado.' : 'Observação opcional'"
                            @input="customizeDescription(row)"
                        ></textarea>
                    </label>
                </div>
            </div>
        </div>

        <p v-if="form.errors.rows" class="text-xs text-red-600">{{ form.errors.rows }}</p>
        <div class="flex items-center justify-end gap-2">
            <button v-if="batchHasRecords" type="button" class="rounded-md px-3 py-2 text-sm text-red-600 hover:bg-red-50" :disabled="loading || form.processing" @click="destroyBatch">
                Eliminar trabalho de casa desta data
            </button>
            <button type="button" class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50" :disabled="loading || form.processing" @click="submit">
                Guardar trabalho de casa
            </button>
        </div>
    </section>
</template>
