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
const form = useForm<{
    class_id: number | null;
    source: string;
    file: File | null;
}>({
    class_id: props.classes[0]?.id ?? null,
    source: props.sources[0]?.key ?? '',
    file: null,
});

const chosenSource = computed(() =>
    props.sources.find((source) => source.key === form.source),
);
const fileName = computed(() => form.file?.name ?? null);

/**
 * How big the chosen file is, and when it was last written.
 *
 * Two exports of two different classes routinely leave a platform under the
 * same name. The name alone therefore identifies nothing, and a teacher who has
 * just seen the wrong class on screen has no way to tell from «teste.csv»
 * whether they picked up the right one this time. The size and the timestamp
 * cost nothing and answer it.
 */
const fileDetail = computed(() => {
    if (form.file === null) {
        return null;
    }

    const kilobytes = Math.max(1, Math.round(form.file.size / 1024));
    const modified = new Date(form.file.lastModified).toLocaleString('pt-PT');

    return `${kilobytes} KB · modificado ${modified}`;
});

function onFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    form.file = input.files?.[0] ?? null;

    // Clearing the control after taking the file, on purpose.
    //
    // A file input holds the chosen path as its value and fires `change` only
    // when that value changes. Export a fresh file over the old one — same
    // folder, same name — pick it again, and the path is identical: no event,
    // `form.file` still references the PREVIOUS selection, and the previous
    // file is uploaded a second time. The import that comes back is genuinely
    // new and genuinely shows the old class, which is exactly what was seen in
    // the smoke, and exactly why renaming the file appeared to fix it.
    //
    // Emptied here, every subsequent selection is a change from nothing and the
    // event always fires. The File object above is already captured and is
    // unaffected; the filename on screen comes from it, not from the input.
    input.value = '';
}

function submit(): void {
    form.post('/imports/correction', { forceFormData: true });
}
</script>

<template>
    <Head title="Importar resultados de outra plataforma" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <div>
            <Heading
                title="Importar resultados de outra plataforma"
                description="Importe resultados provenientes de plataformas de aplicação de testes."
            />
            <Link
                href="/assessments"
                class="text-sm text-muted-foreground hover:underline"
                >← Voltar a Avaliações</Link
            >
        </div>

        <p
            v-if="classes.length === 0"
            class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            Ainda não tem turmas. Crie uma turma antes de importar resultados.
        </p>

        <form v-else class="space-y-5" @submit.prevent="submit">
            <div class="space-y-1.5">
                <label for="import-class" class="text-sm font-medium"
                    >Turma</label
                >
                <select
                    id="import-class"
                    v-model="form.class_id"
                    class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option
                        v-for="option in classes"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.label }} — {{ option.subject }}
                    </option>
                </select>
                <p v-if="form.errors.class_id" class="text-sm text-destructive">
                    {{ form.errors.class_id }}
                </p>
            </div>

            <fieldset class="space-y-1.5">
                <legend class="text-sm font-medium">
                    Plataforma de origem
                </legend>
                <div
                    v-for="option in sources"
                    :key="option.key"
                    class="flex items-start gap-2 rounded-md border border-border p-3"
                >
                    <input
                        :id="`source-${option.key}`"
                        v-model="form.source"
                        type="radio"
                        :value="option.key"
                        class="mt-1"
                    />
                    <label :for="`source-${option.key}`" class="cursor-pointer">
                        <span class="block text-sm font-medium">{{
                            option.label
                        }}</span>
                        <span class="block text-xs text-muted-foreground">{{
                            option.hint
                        }}</span>
                    </label>
                </div>
                <p v-if="form.errors.source" class="text-sm text-destructive">
                    {{ form.errors.source }}
                </p>
            </fieldset>

            <div class="space-y-1.5">
                <span id="import-file-label" class="block text-sm font-medium"
                    >Ficheiro exportado</span
                >

                <!--
                  The native input stays in the DOM and keeps focus and keyboard
                  activation; only its default rendering is hidden, because that
                  rendering gives almost no sign of being a button. The label
                  beside it carries the affordance and mirrors the input's focus
                  ring through `peer-focus-visible`, so the control is as usable
                  by keyboard as it looks by mouse.
                -->
                <div class="flex flex-wrap items-center gap-3">
                    <input
                        id="import-file"
                        type="file"
                        :accept="chosenSource?.accept"
                        class="peer sr-only"
                        aria-labelledby="import-file-label"
                        :aria-describedby="
                            form.errors.file
                                ? 'import-file-error'
                                : 'import-file-hint'
                        "
                        @change="onFile"
                    />
                    <label
                        for="import-file"
                        class="inline-flex cursor-pointer items-center rounded-md border border-input bg-background px-4 py-2 text-sm font-medium peer-focus-visible:ring-2 peer-focus-visible:ring-offset-2 hover:bg-muted/60 focus-visible:ring-ring"
                    >
                        {{
                            fileName === null
                                ? 'Selecionar ficheiro CSV'
                                : 'Alterar ficheiro'
                        }}
                    </label>
                    <span
                        class="text-sm"
                        :class="
                            fileName === null
                                ? 'text-muted-foreground'
                                : 'font-medium'
                        "
                    >
                        {{ fileName ?? 'nenhum ficheiro selecionado' }}
                    </span>
                </div>

                <p v-if="fileDetail" class="text-xs text-muted-foreground">
                    {{ fileDetail }}
                </p>

                <p id="import-file-hint" class="text-xs text-muted-foreground">
                    Formato aceite:
                    {{ chosenSource?.extensions.join(', ').toUpperCase() }}. O
                    ficheiro é guardado temporariamente em privado e eliminado
                    após a importação ou cancelamento.
                </p>
                <p
                    v-if="form.errors.file"
                    id="import-file-error"
                    class="text-sm text-destructive"
                >
                    {{ form.errors.file }}
                </p>
            </div>

            <div class="flex items-center gap-3 border-t border-border pt-5">
                <button
                    type="submit"
                    class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground disabled:opacity-50"
                    :disabled="form.processing || form.file === null"
                >
                    {{ form.processing ? 'A analisar…' : 'Analisar' }}
                </button>
                <span
                    v-if="form.file === null"
                    class="text-xs text-muted-foreground"
                >
                    Selecione um ficheiro para poder analisar.
                </span>
            </div>

            <p class="text-xs text-muted-foreground">
                A análise apenas lê o ficheiro. Nenhum resultado é adicionado às
                avaliações até confirmar a importação no último passo.
            </p>
        </form>
    </div>
</template>
