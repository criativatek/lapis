<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, TriangleAlert } from '@lucide/vue';
import Heading from '@/components/Heading.vue';

type Cell = { period_label: string; state: 'none' | 'kept' | 'refreshed'; before: string | null; after: string | null; changed: boolean };
type Row = { name: string; class_number: number | null; cells: Cell[]; changed: boolean };
type Preview = {
    from: { version: number; name: string } | null;
    to: { version: number; name: string };
    periods: string[];
    rows: Row[];
    affected_enrollment_count: number;
    recalculated_result_count: number;
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string };
    toVersionUlid: string;
    preview: Preview;
}>();

const form = useForm({
    to_version: props.toVersionUlid,
    reason: '',
});

// "—" for a null (no computable result), never 0.
function value(cell: string | null): string {
    return cell === null ? '—' : `${Number(cell).toFixed(1)}%`;
}

function submit(): void {
    form.post(`/classes/${props.schoolClass.ulid}/profile-migration`);
}
</script>

<template>
    <Head :title="`Migrar perfil — ${schoolClass.label}`" />

    <div class="space-y-4 p-4">
        <div>
            <Heading :title="`Migrar perfil — ${schoolClass.label}`" :description="schoolClass.subject" />
            <Link :href="`/classes/${schoolClass.ulid}`" class="text-sm text-muted-foreground hover:underline">← Voltar à turma</Link>
        </div>

        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-border p-4 text-sm">
            <span class="text-muted-foreground">
                {{ preview.from ? `${preview.from.name} v${preview.from.version}` : 'Sem perfil' }}
            </span>
            <ArrowRight class="size-4 text-muted-foreground" />
            <span class="font-medium">{{ preview.to.name }} v{{ preview.to.version }}</span>
            <span class="ml-auto text-muted-foreground">
                {{ preview.affected_enrollment_count }} alunos afetados · {{ preview.recalculated_result_count }} resultados recalculados
            </span>
        </div>

        <p class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            A migração muda a versão de perfil da turma e recalcula as propostas em aberto. As classificações já
            confirmadas ou publicadas mantêm-se como estão, na versão que as produziu — o histórico não é recalculado.
        </p>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium" rowspan="2">Aluno</th>
                        <th v-for="label in preview.periods" :key="label" class="border-l border-border px-3 py-2 text-center font-medium" colspan="2">
                            {{ label }}
                        </th>
                    </tr>
                    <tr class="text-xs text-muted-foreground">
                        <template v-for="label in preview.periods" :key="`h-${label}`">
                            <th class="border-l border-border px-3 py-1 text-right font-normal">Antes</th>
                            <th class="px-3 py-1 text-right font-normal">Depois</th>
                        </template>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="(row, rowIndex) in preview.rows" :key="rowIndex" class="hover:bg-muted/20" :class="{ 'bg-amber-50/40 dark:bg-amber-950/20': row.changed }">
                        <td class="px-3 py-2 whitespace-nowrap">
                            <span class="text-muted-foreground">{{ row.class_number ?? '—' }}</span>
                            <span class="ml-2 font-medium">{{ row.name }}</span>
                        </td>
                        <template v-for="(cell, index) in row.cells" :key="index">
                            <td class="border-l border-border px-3 py-2 text-right tabular-nums text-muted-foreground">
                                {{ cell.state === 'none' ? '—' : value(cell.before) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                <span v-if="cell.state === 'none'" class="text-muted-foreground">—</span>
                                <span v-else-if="cell.state === 'kept'" class="text-xs text-muted-foreground" title="Decisão confirmada — mantida">mantida</span>
                                <span v-else-if="cell.changed" class="inline-flex items-center gap-1 font-semibold text-amber-700 dark:text-amber-400">
                                    {{ value(cell.after) }}
                                    <TriangleAlert class="size-3" aria-hidden="true" />
                                    <span class="text-xs font-normal">alterado</span>
                                </span>
                                <span v-else class="text-muted-foreground">
                                    {{ value(cell.after) }}
                                </span>
                            </td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>

        <form class="space-y-3 rounded-lg border border-border p-4" @submit.prevent="submit">
            <label class="block text-sm">
                <span class="mb-1 block font-medium">Motivo da migração (obrigatório)</span>
                <input
                    v-model="form.reason"
                    type="text"
                    maxlength="500"
                    class="w-full rounded-md border border-border bg-background px-3 py-2"
                    placeholder="Ex.: correção da ponderação de Escrita aprovada em conselho de turma"
                    :aria-describedby="form.errors.reason ? 'migration-reason-error' : undefined"
                />
            </label>
            <p v-if="form.errors.reason" id="migration-reason-error" class="text-xs text-red-600">{{ form.errors.reason }}</p>
            <div class="flex gap-2">
                <Link
                    :href="`/classes/${schoolClass.ulid}`"
                    class="rounded-md border border-border px-4 py-3 text-sm hover:bg-muted/40"
                >
                    Cancelar
                </Link>
                <button
                    type="submit"
                    class="rounded-md bg-primary px-4 py-3 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Confirmar migração
                </button>
            </div>
        </form>
    </div>
</template>
