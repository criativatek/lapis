<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type RowType = 'school_identity' | 'academic_year' | 'subject' | 'scale' | 'assessment_profile';
interface Row { type: RowType; label: string; status: 'new' | 'existing' | 'conflict' | 'invalid'; data: Record<string, unknown> }

const props = defineProps<{
    plan: { summary: Record<string, number>; rows: Row[] };
    exportedAt: string;
    productVersion: string | null;
}>();

const labels = { new: 'Novo', existing: 'Existente', conflict: 'Conflito', invalid: 'Inválido' };
const typeLabels: Record<RowType, string> = {
    school_identity: 'Identidade da escola',
    academic_year: 'Ano letivo',
    subject: 'Disciplina',
    scale: 'Escala',
    assessment_profile: 'Perfil de avaliação',
};

const statusCardClass: Record<Row['status'], string> = {
    new: 'border-border',
    existing: 'border-border',
    conflict: 'border-amber-500/40 bg-amber-500/5',
    invalid: 'border-destructive/40 bg-destructive/5',
};
const statusBadgeClass: Record<Row['status'], string> = {
    new: 'bg-primary/10 text-primary',
    existing: 'bg-muted text-muted-foreground',
    conflict: 'bg-amber-500/15 text-amber-700',
    invalid: 'bg-destructive/15 text-destructive',
};

function rowName(row: Row): string {
    switch (row.type) {
        case 'academic_year':
            return String(row.data.label ?? row.label);
        case 'subject':
            return `${row.data.code ?? ''} — ${row.data.name ?? ''}`;
        case 'scale':
            return String(row.data.name ?? row.label);
        case 'assessment_profile':
            return String(row.data.name ?? row.label);
        case 'school_identity':
            return 'Identidade da escola';
    }
}

function rowContext(row: Row): string | null {
    if (row.type === 'assessment_profile') {
        return `${row.data.academic_year_label ?? ''} · ${row.data.subject_code ?? ''}`;
    }

    return null;
}

const createdOn = computed(() =>
    new Date(props.exportedAt).toLocaleDateString('pt-PT', { day: 'numeric', month: 'long', year: 'numeric' }),
);

const types = Array.from(new Set(props.plan.rows.filter((row) => row.status === 'new').map((row) => row.type)));
const form = useForm({ types });

function confirm(): void {
    if (form.processing || types.length === 0) {
        return;
    }

    form.post('/configuracao/importar/confirmar');
}
</script>

<template>
    <Head title="Pré-visualizar importação" />
    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <Heading title="Pré-visualizar importação" description="Reveja o conteúdo antes de decidir o que importar." />
            <Link href="/configuracao/importar" class="text-sm text-muted-foreground hover:underline">← Escolher outro ficheiro</Link>
        </div>

        <p class="text-sm text-muted-foreground">
            Ficheiro criado no LÁPIS em {{ createdOn }}<template v-if="productVersion"> (v{{ productVersion }})</template>.
        </p>

        <div class="flex items-start gap-3 rounded-lg border border-primary/25 bg-primary/5 p-4 text-sm">
            <Info class="mt-0.5 size-4 shrink-0 text-primary" />
            <p>Este ficheiro contém apenas configurações. Não inclui alunos, classificações, registos pedagógicos ou outros dados pessoais.</p>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div v-for="status in (['new', 'existing', 'conflict', 'invalid'] as const)" :key="status" class="rounded-lg border p-3 text-center" :class="statusCardClass[status]">
                <strong class="block text-xl">{{ plan.summary[status] }}</strong>
                <span class="text-xs text-muted-foreground">{{ labels[status] }}</span>
            </div>
        </div>

        <div class="divide-y rounded-lg border border-border">
            <div v-for="row in plan.rows" :key="`${row.type}-${row.label}`" class="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ typeLabels[row.type] }}</p>
                    <p class="text-sm font-medium break-words">{{ rowName(row) }}</p>
                    <p v-if="rowContext(row)" class="text-xs text-muted-foreground">{{ rowContext(row) }}</p>
                </div>
                <span class="w-fit shrink-0 rounded-full px-2.5 py-1 text-xs font-medium" :class="statusBadgeClass[row.status]">{{ labels[row.status] }}</span>
            </div>
        </div>

        <p v-if="plan.summary.conflict" class="text-sm text-amber-700">Os conflitos serão ignorados. A configuração existente nunca é substituída.</p>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-muted-foreground">{{ types.length === 0 ? 'Nada novo para importar.' : `${plan.summary.new} novo(s) por importar.` }}</p>
            <Button type="button" :disabled="form.processing || types.length === 0" class="shrink-0" @click="confirm">
                {{ form.processing ? 'A importar…' : 'Importar novos elementos' }}
            </Button>
        </div>
    </div>
</template>
