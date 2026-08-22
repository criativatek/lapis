<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
const form = useForm<{ file: File | null }>({ file: null });
function choose(event: Event): void {
 form.file = (event.target as HTMLInputElement).files?.[0] ?? null; 
}
function submit(): void {
 form.post('/configuracao/importar/preview', { forceFormData: true }); 
}
</script>
<template>
    <Head title="Importar configuração" />
    <div class="mx-auto w-full max-w-2xl space-y-6 p-4">
        <div><Heading title="Importar configuração" description="Analise o pacote antes de criar qualquer configuração." /><Link href="/configuracao/partilhar" class="text-sm text-primary hover:underline">← Partilhar configuração</Link></div>
        <div class="rounded-md border border-primary/25 bg-primary/5 p-4 text-sm font-medium">Este ficheiro contém apenas configurações. Não inclui alunos, classificações ou outros dados pessoais.</div>
        <form class="space-y-5" @submit.prevent="submit"><div><label for="configuration-file" class="mb-2 block text-sm font-medium">Pacote JSON</label><input id="configuration-file" type="file" accept=".json,application/json" @change="choose" /><p class="mt-2 text-xs text-muted-foreground">Máximo 20 MB. A análise não escreve dados.</p><p v-if="form.errors.file" class="mt-2 text-sm text-destructive">{{ form.errors.file }}</p></div><button type="submit" class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground disabled:opacity-50" :disabled="form.processing || !form.file">{{ form.processing ? 'A analisar…' : 'Pré-visualizar' }}</button></form>
    </div>
</template>
