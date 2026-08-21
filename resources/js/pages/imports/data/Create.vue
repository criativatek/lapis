<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';

const form = useForm<{ file: File | null }>({ file: null });

const fileName = computed(() => form.file?.name ?? null);

const fileDetail = computed(() => {
    if (form.file === null) {
        return null;
    }

    const kilobytes = Math.max(1, Math.round(form.file.size / 1024));

    return `${kilobytes} KB`;
});

function onFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    form.file = input.files?.[0] ?? null;
    // Same reasoning as the correction-import wizard: re-selecting an
    // export saved over an identical path/name would otherwise not fire
    // a change event at all.
    input.value = '';
}

function submit(): void {
    form.post('/data-imports', { forceFormData: true });
}
</script>

<template>
    <Head title="Importar dados" />

    <div class="mx-auto w-full max-w-2xl space-y-6 p-4">
        <div>
            <Heading
                title="Importar dados"
                description="Restaure dados a partir de uma exportação criada pelo LÁPIS."
            />
            <Link
                href="/settings/profile"
                class="text-sm text-muted-foreground hover:underline"
                >← Configuração</Link
            >
        </div>

        <form class="space-y-5" @submit.prevent="submit">
            <div class="space-y-1.5">
                <span id="import-file-label" class="block text-sm font-medium"
                    >Ficheiro</span
                >

                <div class="flex flex-wrap items-center gap-3">
                    <input
                        id="import-file"
                        type="file"
                        accept=".zip,.json,application/zip,application/json"
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
                                ? 'Selecionar backup'
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
                    Aceita o ZIP completo de uma exportação do LÁPIS, ou apenas
                    o ficheiro backup-lapis.json. O ficheiro é guardado
                    temporariamente em privado e eliminado após a importação ou
                    cancelamento.
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
                    {{ form.processing ? 'A analisar…' : 'Analisar backup' }}
                </button>
                <span
                    v-if="form.file === null"
                    class="text-xs text-muted-foreground"
                >
                    Selecione um ficheiro para poder analisar.
                </span>
            </div>

            <p class="text-xs text-muted-foreground">
                A análise apenas lê o ficheiro. Nenhum dado é criado até
                confirmar a importação no passo seguinte.
            </p>
        </form>
    </div>
</template>
