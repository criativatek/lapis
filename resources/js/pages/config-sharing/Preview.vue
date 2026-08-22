<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
interface Row { type: string; label: string; status: 'new' | 'existing' | 'conflict' | 'invalid' }
const props = defineProps<{ plan: { summary: Record<string, number>; rows: Row[] }; provenance: { organization_name?: string } }>();
const labels = { new: 'Novo', existing: 'Existente', conflict: 'Conflito', invalid: 'Inválido' };
const types = Array.from(new Set(props.plan.rows.filter((row) => row.status === 'new').map((row) => row.type)));
const form = useForm({ types });
function confirm(): void {
 form.post('/configuracao/importar/confirmar'); 
}
</script>
<template>
    <Head title="Pré-visualizar importação" />
    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <div><Heading title="Pré-visualizar importação" :description="`Origem informativa: ${provenance.organization_name ?? 'não indicada'}`" /><Link href="/configuracao/importar" class="text-sm text-primary hover:underline">← Escolher outro ficheiro</Link></div>
        <div class="rounded-md border border-primary/25 bg-primary/5 p-4 text-sm font-medium">Este ficheiro contém apenas configurações. Não inclui alunos, classificações ou outros dados pessoais.</div>
        <div class="grid grid-cols-4 gap-3"><div v-for="status in ['new','existing','conflict','invalid']" :key="status" class="rounded border p-3 text-center"><strong class="block text-xl">{{ plan.summary[status] }}</strong><span class="text-xs">{{ labels[status as keyof typeof labels] }}</span></div></div>
        <div class="divide-y rounded border"><div v-for="row in plan.rows" :key="`${row.type}-${row.label}`" class="flex items-center justify-between p-3"><span>{{ row.label }}</span><span class="rounded bg-muted px-2 py-1 text-xs font-medium">{{ labels[row.status] }}</span></div></div>
        <p v-if="plan.summary.conflict" class="text-sm text-amber-700">Os conflitos serão ignorados. A configuração existente nunca é substituída.</p>
        <button type="button" class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground disabled:opacity-50" :disabled="form.processing || types.length === 0" @click="confirm">{{ form.processing ? 'A importar…' : 'Importar configurações novas' }}</button>
    </div>
</template>
