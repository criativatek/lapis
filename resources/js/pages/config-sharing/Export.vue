<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download, Info } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

interface Item { ulid: string; name?: string; label?: string; code?: string; kind?: string; year?: string; subject?: string }
const props = defineProps<{ options: { hasSchoolIdentity: boolean; academicYears: Item[]; subjects: Item[]; scales: Item[]; assessmentProfiles: Item[] } }>();
const selection = reactive({ school_identity: false, academic_years: [] as string[], subjects: [] as string[], scales: [] as string[], assessment_profiles: [] as string[] });
const processing = ref(false);

const selectedCount = computed(
    () =>
        (selection.school_identity ? 1 : 0) +
        selection.academic_years.length +
        selection.subjects.length +
        selection.scales.length +
        selection.assessment_profiles.length,
);
const selectionLabel = computed(() => (selectedCount.value === 1 ? '1 elemento selecionado' : `${selectedCount.value} elementos selecionados`));
const dependencies = computed(() => props.options.assessmentProfiles.filter((item) => selection.assessment_profiles.includes(item.ulid)).map((item) => `${item.year} · ${item.subject}`).join(', '));

const optionRow =
    'flex cursor-pointer items-start gap-3 rounded-lg border border-border p-3 transition-colors hover:bg-muted/40 focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2 focus-within:ring-offset-background';

async function download(): Promise<void> {
    if (processing.value || selectedCount.value === 0) {
        return;
    }

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
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <Heading title="Partilhar configuração" description="Escolha a estrutura que pretende partilhar com um colega." />
            <Button as-child variant="outline" size="sm" class="self-start sm:self-auto">
                <Link href="/configuracao/importar"><Download class="size-4" /> Importar configuração</Link>
            </Button>
        </div>

        <div class="flex items-start gap-3 rounded-lg border border-primary/25 bg-primary/5 p-4 text-sm">
            <Info class="mt-0.5 size-4 shrink-0 text-primary" />
            <p>Este ficheiro contém apenas configurações. Não inclui alunos, classificações, registos pedagógicos ou outros dados pessoais.</p>
        </div>

        <form class="space-y-6" @submit.prevent="download">
            <div v-if="options.hasSchoolIdentity" class="space-y-2">
                <h3 class="text-sm font-medium">Identidade da escola</h3>
                <label :class="optionRow">
                    <input v-model="selection.school_identity" type="checkbox" class="mt-0.5 size-4 shrink-0 accent-primary" />
                    <span class="min-w-0">
                        <span class="block text-sm font-medium break-words">Identidade da escola</span>
                        <span class="block text-xs text-muted-foreground">Sem logótipo ou ficheiros.</span>
                    </span>
                </label>
            </div>

            <div class="space-y-2">
                <h3 class="text-sm font-medium">Ano letivo</h3>
                <p v-if="options.academicYears.length === 0" class="text-sm text-muted-foreground">Sem anos letivos disponíveis para partilhar.</p>
                <div v-else class="space-y-2">
                    <label v-for="item in options.academicYears" :key="item.ulid" :class="optionRow">
                        <input v-model="selection.academic_years" type="checkbox" :value="item.ulid" class="mt-0.5 size-4 shrink-0 accent-primary" />
                        <span class="min-w-0 text-sm break-words">{{ item.label }}</span>
                    </label>
                </div>
            </div>

            <div class="space-y-2">
                <h3 class="text-sm font-medium">Disciplina</h3>
                <p v-if="options.subjects.length === 0" class="text-sm text-muted-foreground">Sem disciplinas disponíveis para partilhar.</p>
                <div v-else class="space-y-2">
                    <label v-for="item in options.subjects" :key="item.ulid" :class="optionRow">
                        <input v-model="selection.subjects" type="checkbox" :value="item.ulid" class="mt-0.5 size-4 shrink-0 accent-primary" />
                        <span class="min-w-0">
                            <span class="block text-sm font-medium break-words">{{ item.name }}</span>
                            <span class="block text-xs text-muted-foreground">Código: {{ item.code }}</span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="space-y-2">
                <h3 class="text-sm font-medium">Escala</h3>
                <p v-if="options.scales.length === 0" class="text-sm text-muted-foreground">Sem escalas próprias disponíveis para partilhar.</p>
                <div v-else class="space-y-2">
                    <label v-for="item in options.scales" :key="item.ulid" :class="optionRow">
                        <input v-model="selection.scales" type="checkbox" :value="item.ulid" class="mt-0.5 size-4 shrink-0 accent-primary" />
                        <span class="min-w-0 text-sm break-words">{{ item.name }}</span>
                    </label>
                </div>
            </div>

            <div class="space-y-2">
                <h3 class="text-sm font-medium">Perfil de avaliação</h3>
                <p v-if="options.assessmentProfiles.length === 0" class="text-sm text-muted-foreground">Sem perfis de avaliação disponíveis para partilhar.</p>
                <div v-else class="space-y-2">
                    <label v-for="item in options.assessmentProfiles" :key="item.ulid" :class="optionRow">
                        <input v-model="selection.assessment_profiles" type="checkbox" :value="item.ulid" class="mt-0.5 size-4 shrink-0 accent-primary" />
                        <span class="min-w-0">
                            <span class="block text-sm font-medium break-words">{{ item.name }}</span>
                            <span class="block text-xs text-muted-foreground">{{ item.year }} · {{ item.subject }}</span>
                        </span>
                    </label>
                </div>
                <p v-if="dependencies" class="rounded-lg bg-muted p-3 text-sm text-muted-foreground">
                    Incluído automaticamente com os perfis: {{ dependencies }}, escala e domínios associados.
                </p>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-muted-foreground" aria-live="polite">
                    {{ selectedCount === 0 ? 'Selecione pelo menos um elemento para gerar o ficheiro.' : selectionLabel }}
                </p>
                <Button type="submit" :disabled="processing || selectedCount === 0" class="shrink-0">
                    {{ processing ? 'A gerar…' : 'Gerar e descarregar' }}
                </Button>
            </div>
        </form>
    </div>
</template>
