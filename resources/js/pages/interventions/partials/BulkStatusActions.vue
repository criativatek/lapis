<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * «Marcar todas como Em curso» e «Concluir todas as medidas» — o gesto do
 * botão individual, aplicado à turma inteira.
 *
 * O âmbito é a turma e o ano letivo desta página, NUNCA a lista filtrada, e o
 * diálogo di-lo. As contagens vêm do servidor (`preview`) e servem só para o
 * professor decidir; o pedido não leva contagens nem ids — o servidor
 * recalcula o que está elegível no momento da confirmação.
 */
export type BulkPreview = {
    from: string;
    to: string;
    measures: number;
    enrollment_ids: number[];
};

const props = defineProps<{
    classUlid: string;
    preview: Record<string, BulkPreview>;
    enrollments: { id: number; name: string }[];
}>();

/**
 * Avisa a página de que um pedido nasce aqui: o guarda de «alterações por
 * submeter» não tem de o tratar como uma saída que perde o formulário.
 */
const emit = defineEmits<{ submitting: [active: boolean] }>();

const OPERATIONS = [
    { from: 'new', button: 'Marcar todas como Em curso', source: 'Planeada', confirm: 'Marcar como Em curso' },
    { from: 'in_progress', button: 'Concluir todas as medidas', source: 'Em curso', confirm: 'Concluir todas' },
] as const;

const pending = ref<string | null>(null);
const processing = ref(false);

const selected = computed(() => (pending.value ? (props.preview[pending.value] ?? null) : null));
const selectedOperation = computed(() => OPERATIONS.find((operation) => operation.from === pending.value) ?? null);

const studentNames = computed(() => {
    const ids = new Set(selected.value?.enrollment_ids ?? []);

    return props.enrollments.filter((enrollment) => ids.has(enrollment.id)).map((enrollment) => enrollment.name);
});

function count(from: string): number {
    return props.preview[from]?.measures ?? 0;
}

function plural(value: number, one: string, many: string): string {
    return value === 1 ? `1 ${one}` : `${value} ${many}`;
}

function open(from: string): void {
    pending.value = from;
}

function cancel(): void {
    pending.value = null;
}

function run(): void {
    if (!pending.value || processing.value) {
        return;
    }

    processing.value = true;
    emit('submitting', true);

    router.post(
        `/classes/${props.classUlid}/interventions/bulk-status`,
        { from: pending.value },
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
                pending.value = null;
                emit('submitting', false);
            },
        },
    );
}
</script>

<template>
    <section class="space-y-2 rounded-lg border border-border p-3" aria-labelledby="bulk-status-heading">
        <h2 id="bulk-status-heading" class="text-xs font-semibold tracking-wide text-muted-foreground uppercase">Estado em lote</h2>

        <div class="flex flex-wrap gap-2">
            <button
                v-for="operation in OPERATIONS"
                :key="operation.from"
                type="button"
                class="min-h-9 rounded-md border border-border px-3 py-1 text-sm hover:bg-muted/40 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="count(operation.from) === 0 || processing"
                @click="open(operation.from)"
            >
                {{ operation.button }} ({{ count(operation.from) }})
            </button>
        </div>

        <div v-if="selected && selectedOperation" role="alertdialog" aria-labelledby="bulk-status-confirm-title" class="space-y-2 rounded-md border border-primary/40 bg-muted/30 p-3 text-sm">
            <p id="bulk-status-confirm-title" class="font-medium">{{ selectedOperation.button }}</p>
            <p>
                Vai alterar {{ plural(selected.measures, 'medida', 'medidas') }} no estado «{{ selectedOperation.source }}», de {{ plural(selected.enrollment_ids.length, 'aluno', 'alunos') }}.
            </p>
            <p class="text-xs text-muted-foreground">
                Aplica-se a toda a turma e ao ano letivo, não apenas ao que os filtros mostram. As medidas noutros estados ficam como estão e os registos de acompanhamento não são alterados.
            </p>
            <p v-if="selected.from === 'in_progress'" class="text-xs text-muted-foreground">
                A conclusão em lote é administrativa: não constitui uma avaliação da eficácia das medidas. Os acompanhamentos e as avaliações de eficácia já registados mantêm-se, e não é criada nenhuma avaliação.
            </p>
            <details v-if="studentNames.length > 0" class="text-xs">
                <summary class="cursor-pointer text-muted-foreground">Ver os alunos abrangidos</summary>
                <ul class="mt-1 columns-2 gap-4">
                    <li v-for="name in studentNames" :key="name">{{ name }}</li>
                </ul>
            </details>
            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-md px-3 py-2 text-sm text-muted-foreground hover:underline" :disabled="processing" @click="cancel">Cancelar</button>
                <button type="button" class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50" :disabled="processing" @click="run">
                    {{ selectedOperation.confirm }}
                </button>
            </div>
        </div>
    </section>
</template>
