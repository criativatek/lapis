<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';

interface Item { ulid: string; name?: string; label?: string; code?: string; kind?: string; year?: string; subject?: string }
const props = defineProps<{ options: { hasSchoolIdentity: boolean; academicYears: Item[]; subjects: Item[]; scales: Item[]; assessmentProfiles: Item[] } }>();
const selection = reactive({ school_identity: false, academic_years: [] as string[], subjects: [] as string[], scales: [] as string[], assessment_profiles: [] as string[] });
const processing = ref(false);
const dependencies = computed(() => props.options.assessmentProfiles.filter((item) => selection.assessment_profiles.includes(item.ulid)).map((item) => `${item.year} · ${item.subject}`).join(', '));
async function download(): Promise<void> {
    processing.value = true;

    try {
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        const response = await fetch('/configuracao/partilhar', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            body: JSON.stringify(selection),
        });

        if (!response.ok) {
            throw new Error('Não foi possível gerar o pacote.');
        }

        const url = URL.createObjectURL(await response.blob());
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = 'lapis-configuracao.json';
        anchor.click();
        URL.revokeObjectURL(url);
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Head title="Partilhar configuração" />
    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <div><Heading title="Partilhar configuração" description="Escolha apenas a estrutura que quer entregar a um colega." /><Link href="/configuracao/importar" class="text-sm text-primary hover:underline">Importar configuração →</Link></div>
        <div class="rounded-md border border-primary/25 bg-primary/5 p-4 text-sm font-medium">Este ficheiro contém apenas configurações. Não inclui alunos, classificações ou outros dados pessoais.</div>
        <form class="space-y-6" @submit.prevent="download">
            <label v-if="options.hasSchoolIdentity" class="flex gap-3"><input v-model="selection.school_identity" type="checkbox" /> <span><strong>Identidade da escola</strong><small class="block text-muted-foreground">Sem logótipo ou ficheiros.</small></span></label>
            <fieldset><legend class="mb-2 font-medium">Ano letivo</legend><label v-for="item in options.academicYears" :key="item.ulid" class="mb-2 flex gap-3"><input v-model="selection.academic_years" type="checkbox" :value="item.ulid" />{{ item.label }}</label></fieldset>
            <fieldset><legend class="mb-2 font-medium">Disciplina</legend><label v-for="item in options.subjects" :key="item.ulid" class="mb-2 flex gap-3"><input v-model="selection.subjects" type="checkbox" :value="item.ulid" />{{ item.name }} ({{ item.code }})</label></fieldset>
            <fieldset><legend class="mb-2 font-medium">Escala</legend><label v-for="item in options.scales" :key="item.ulid" class="mb-2 flex gap-3"><input v-model="selection.scales" type="checkbox" :value="item.ulid" />{{ item.name }}</label></fieldset>
            <fieldset><legend class="mb-2 font-medium">Perfil de avaliação</legend><label v-for="item in options.assessmentProfiles" :key="item.ulid" class="mb-2 flex gap-3"><input v-model="selection.assessment_profiles" type="checkbox" :value="item.ulid" /><span>{{ item.name }} <small class="block text-muted-foreground">{{ item.year }} · {{ item.subject }}</small></span></label><p v-if="dependencies" class="mt-3 rounded bg-muted p-3 text-sm">Incluído automaticamente com os perfis: {{ dependencies }}, escala e domínios associados.</p></fieldset>
            <button type="submit" class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground disabled:opacity-50" :disabled="processing">{{ processing ? 'A gerar…' : 'Gerar e descarregar' }}</button>
        </form>
    </div>
</template>
