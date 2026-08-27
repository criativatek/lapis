<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Info, UploadCloud, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

const form = useForm<{ file: File | null }>({ file: null });
const fileInput = ref<InstanceType<typeof HTMLInputElement> | null>(null);
const isDragging = ref(false);

const fileSize = computed(() => (form.file ? formatFileSize(form.file.size) : null));

function formatFileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}

function onFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    form.file = input.files?.[0] ?? null;
    isDragging.value = false;
}

function changeFile(): void {
    fileInput.value?.click();
}

function removeFile(): void {
    form.file = null;

    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

function submit(): void {
    if (form.processing || !form.file) {
        return;
    }

    form.post('/configuracao/importar/preview', { forceFormData: true });
}
</script>

<template>
    <Head title="Importar configuração" />
    <div class="mx-auto w-full max-w-2xl space-y-6 p-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <Heading title="Importar configuração" description="Analise o conteúdo antes de adicionar qualquer configuração à sua conta." />
            <Button as-child variant="outline" size="sm" class="self-start sm:self-auto">
                <Link href="/configuracao/partilhar">← Partilhar configuração</Link>
            </Button>
        </div>

        <div class="flex items-start gap-3 rounded-lg border border-primary/25 bg-primary/5 p-4 text-sm">
            <Info class="mt-0.5 size-4 shrink-0 text-primary" />
            <p>Este ficheiro contém apenas configurações. Não inclui alunos, classificações, registos pedagógicos ou outros dados pessoais.</p>
        </div>

        <form class="space-y-5" @submit.prevent="submit">
            <div class="space-y-2">
                <span id="configuration-file-label" class="block text-sm font-medium">Ficheiro de configuração Lapispro</span>

                <div class="relative">
                    <!-- The native input stays mounted and covers the whole zone
                         (transparent, never display:none) so click-to-choose,
                         native drag & drop and keyboard activation all come from
                         the browser itself. Once a file is picked it shrinks to
                         sr-only — "Alterar ficheiro" just re-opens it. -->
                    <input
                        id="configuration-file"
                        ref="fileInput"
                        type="file"
                        accept=".json,application/json,text/plain"
                        :class="form.file ? 'sr-only' : 'absolute inset-0 size-full cursor-pointer opacity-0'"
                        aria-labelledby="configuration-file-label"
                        :aria-describedby="form.errors.file ? 'configuration-file-error' : 'configuration-file-hint'"
                        @change="onFile"
                        @dragenter="isDragging = true"
                        @dragleave="isDragging = false"
                        @drop="isDragging = false"
                    />

                    <div
                        v-if="!form.file"
                        class="pointer-events-none flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-8 text-center transition-colors"
                        :class="isDragging ? 'border-primary bg-primary/5' : 'border-border'"
                    >
                        <UploadCloud class="size-8 text-muted-foreground" />
                        <p class="text-sm font-medium">Selecionar ficheiro de configuração</p>
                        <p class="text-sm text-muted-foreground">Arraste o ficheiro para aqui ou clique para escolher</p>
                        <p id="configuration-file-hint" class="text-xs text-muted-foreground">JSON · máximo 20 MB</p>
                    </div>

                    <div v-else class="flex items-start gap-3 rounded-lg border border-border p-4">
                        <CheckCircle2 class="mt-0.5 size-5 shrink-0 text-emerald-600" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">Ficheiro selecionado</p>
                            <p class="truncate text-sm text-muted-foreground" :title="form.file.name">{{ form.file.name }}</p>
                            <p class="text-xs text-muted-foreground">{{ fileSize }}</p>
                        </div>
                        <div class="flex shrink-0 flex-col gap-1 sm:flex-row">
                            <Button type="button" variant="ghost" size="sm" @click="changeFile">Alterar ficheiro</Button>
                            <Button type="button" variant="ghost" size="sm" @click="removeFile"><X class="size-4" /> Remover</Button>
                        </div>
                    </div>
                </div>

                <p v-if="form.errors.file" id="configuration-file-error" class="text-sm text-destructive">{{ form.errors.file }}</p>
            </div>

            <p class="text-sm text-muted-foreground">Antes de importar, poderá rever os elementos novos, existentes, conflitos e elementos inválidos.</p>

            <Button type="submit" :disabled="form.processing || !form.file">
                {{ form.processing ? 'A analisar…' : 'Pré-visualizar configuração' }}
            </Button>
        </form>
    </div>
</template>
