<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, Printer } from '@lucide/vue';

type Cell = { value: string | null; status: 'confirmed' | 'published' | null };
type Row = {
    enrollment_id: number;
    name: string;
    class_number: number | null;
    include_evidence_in_report: boolean | null;
    cells: Cell[];
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string; academic_year: string };
    periods: string[];
    rows: Row[];
    includeEvidenceInReport: boolean;
}>();

// "—" for a null (no decided grade), never 0.
function grade(cell: Cell): string {
    return cell.value === null ? '—' : Number(cell.value).toFixed(1);
}

function print(): void {
    window.print();
}

function updateClassDefault(event: Event): void {
    router.put(
        `/classes/${props.schoolClass.ulid}/report/evidence-setting`,
        { include_evidence_in_report: (event.target as HTMLInputElement).checked },
        { preserveScroll: true },
    );
}

// '' (inherit) / '1' (always) / '0' (never) — a plain boolean select can't
// express "no override", so the DOM value is a string tri-state instead.
function studentOverrideValue(row: Row): string {
    if (row.include_evidence_in_report === null) {
        return '';
    }

    return row.include_evidence_in_report ? '1' : '0';
}

function updateStudentOverride(row: Row, event: Event): void {
    const raw = (event.target as HTMLSelectElement).value;
    const value = raw === '' ? null : raw === '1';

    router.put(
        `/classes/${props.schoolClass.ulid}/report/students/${row.enrollment_id}/evidence-setting`,
        { include_evidence_in_report: value },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="`Pauta — ${schoolClass.label}`" />

    <div class="space-y-4 p-4">
        <div class="print-hide flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Pauta — {{ schoolClass.label }}</h1>
                <Link href="/reports/pautas" class="text-sm text-muted-foreground hover:underline">← Voltar às pautas</Link>
            </div>
            <div class="flex gap-2">
                <a
                    :href="`/classes/${schoolClass.ulid}/report/export`"
                    class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm hover:bg-muted/40"
                >
                    <Download class="size-4" /> Exportar CSV
                </a>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:opacity-90"
                    @click="print"
                >
                    <Printer class="size-4" /> Imprimir
                </button>
            </div>
        </div>

        <label class="print-hide flex items-center gap-2 text-sm">
            <input
                type="checkbox"
                :checked="includeEvidenceInReport"
                class="rounded border-border"
                @change="updateClassDefault"
            />
            Incluir dados que constam nos Registos do professor
        </label>

        <div class="pauta-print space-y-3">
            <div class="hidden print:block">
                <h1 class="text-lg font-semibold">Pauta de classificações — {{ schoolClass.label }}</h1>
                <p class="text-sm">{{ schoolClass.subject }} · {{ schoolClass.academic_year }}</p>
            </div>

            <div class="overflow-x-auto rounded-lg border border-border print:rounded-none print:border-0">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2 font-medium">Nº</th>
                            <th class="px-3 py-2 font-medium">Aluno</th>
                            <th v-for="label in periods" :key="label" class="px-3 py-2 text-right font-medium">{{ label }}</th>
                            <th class="print-hide px-3 py-2 font-medium">Registos</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="(row, index) in rows" :key="index">
                            <td class="px-3 py-2 text-muted-foreground tabular-nums">{{ row.class_number ?? '—' }}</td>
                            <td class="px-3 py-2 font-medium whitespace-nowrap">{{ row.name }}</td>
                            <td v-for="(cell, cellIndex) in row.cells" :key="cellIndex" class="px-3 py-2 text-right tabular-nums">
                                <span :class="{ 'text-muted-foreground': cell.value === null }">{{ grade(cell) }}</span>
                                <span
                                    v-if="cell.status"
                                    class="ml-1 inline-block size-1.5 rounded-full align-middle print:hidden"
                                    :class="cell.status === 'published' ? 'bg-emerald-500' : 'bg-amber-500'"
                                    :title="cell.status === 'published' ? 'Publicada' : 'Confirmada'"
                                ></span>
                            </td>
                            <td class="print-hide px-3 py-2">
                                <select
                                    :value="studentOverrideValue(row)"
                                    class="rounded-md border border-border bg-background px-2 py-1 text-xs"
                                    :title="'Por omissão da turma: ' + (includeEvidenceInReport ? 'incluir' : 'não incluir')"
                                    @change="updateStudentOverride(row, $event)"
                                >
                                    <option value="">Turma</option>
                                    <option value="1">Incluir</option>
                                    <option value="0">Não incluir</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-muted-foreground print:text-black">
                Apenas classificações decididas (confirmadas ou publicadas). O ponto verde assinala publicada, o âmbar confirmada por publicar. "—" significa sem classificação decidida.
            </p>
        </div>
    </div>
</template>

<style>
/* Print only the pauta — hide the app shell with the visibility trick, which
   does not depend on the layout's markup. */
@media print {
    body * {
        visibility: hidden;
    }
    .pauta-print,
    .pauta-print * {
        visibility: visible;
    }
    .pauta-print {
        position: absolute;
        inset: 0;
    }
    .print-hide {
        display: none !important;
    }
}
</style>
