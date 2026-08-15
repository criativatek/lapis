<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';

type ClassOption = { id: number; ulid: string; label: string; subject: string };
type SourceOption = {
    key: string;
    label: string;
    hint: string;
    extensions: string[];
    accept: string;
};

const props = defineProps<{
    classes: ClassOption[];
    sources: SourceOption[];
    acceptedExtensions?: string[];
}>();

// The list comes from the parsers actually registered on the server, so a
// source that has no reader simply is not here. No disabled entries teasing
// something that does not work yet.
const form = useForm<{ class_id: number | null; source: string; file: File | null }>({
    class_id: props.classes[0]?.id ?? null,
    source: props.sources[0]?.key ?? '',
    file: null,
});

const chosenSource = computed(() => props.sources.find((source) => source.key === form.source));

function onFile(event: Event): void {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submit(): void {
    form.post('/imports/correction', { forceFormData: true });
}
</script>

<template>
    <Head title="Importar grelha" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <div>
            <Heading
                title="Importar grelha"
                description="Importe resultados e grelhas de correção provenientes de outras plataformas de aplicação de testes."
            />
            <Link href="/assessments" class="text-sm text-muted-foreground hover:underline">← Voltar a Avaliações</Link>
        </div>

        <p v-if="classes.length === 0" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Ainda não tem turmas. Crie uma turma antes de importar resultados.
        </p>

        <form v-else class="space-y-5" @submit.prevent="submit">
            <div class="space-y-1.5">
                <label for="import-class" class="text-sm font-medium">Turma</label>
                <select id="import-class" v-model="form.class_id" class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm">
                    <option v-for="option in classes" :key="option.id" :value="option.id">
                        {{ option.label }} — {{ option.subject }}
                    </option>
                </select>
                <p v-if="form.errors.class_id" class="text-sm text-destructive">{{ form.errors.class_id }}</p>
            </div>

            <fieldset class="space-y-1.5">
                <legend class="text-sm font-medium">Origem</legend>
                <div v-for="option in sources" :key="option.key" class="flex items-start gap-2 rounded-md border border-border p-3">
                    <input
                        :id="`source-${option.key}`"
                        v-model="form.source"
                        type="radio"
                        :value="option.key"
                        class="mt-1"
                    />
                    <label :for="`source-${option.key}`" class="cursor-pointer">
                        <span class="block text-sm font-medium">{{ option.label }}</span>
                        <span class="block text-xs text-muted-foreground">{{ option.hint }}</span>
                    </label>
                </div>
                <p v-if="form.errors.source" class="text-sm text-destructive">{{ form.errors.source }}</p>
            </fieldset>

            <div class="space-y-1.5">
                <label for="import-file" class="text-sm font-medium">Ficheiro</label>
                <input
                    id="import-file"
                    type="file"
                    :accept="chosenSource?.accept"
                    class="block w-full text-sm"
                    :aria-describedby="form.errors.file ? 'import-file-error' : 'import-file-hint'"
                    @change="onFile"
                />
                <p id="import-file-hint" class="text-xs text-muted-foreground">
                    Formatos aceites: {{ chosenSource?.extensions.join(', ') }}. O ficheiro é guardado em privado e
                    apagado assim que a importação terminar ou for cancelada.
                </p>
                <p v-if="form.errors.file" id="import-file-error" class="text-sm text-destructive">{{ form.errors.file }}</p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50"
                    :disabled="form.processing || form.file === null"
                >
                    {{ form.processing ? 'A analisar…' : 'Analisar' }}
                </button>
                <span v-if="form.file === null" class="text-xs text-muted-foreground">Escolha um ficheiro para continuar.</span>
            </div>

            <p class="text-xs text-muted-foreground">
                Analisar apenas lê o ficheiro. Nada é gravado nas suas avaliações até confirmar a importação no
                último passo.
            </p>
        </form>
    </div>
</template>
