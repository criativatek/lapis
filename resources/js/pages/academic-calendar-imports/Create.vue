<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, FileUp } from '@lucide/vue';
import FileInput from '@/components/FileInput.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

defineProps<{
    academicYear: {
        label: string;
        starts_on: string;
        ends_on: string;
    } | null;
}>();

const form = useForm<{ calendar: File | null }>({ calendar: null });

function submit(): void {
    form.post('/academic-calendar-imports', { forceFormData: true });
}

function onFileChange(event: Event): void {
    form.calendar = (event.target as HTMLInputElement).files?.[0] ?? null;
}
</script>

<template>
    <Head title="Importar calendário escolar" />

    <div class="mx-auto w-full max-w-2xl space-y-6 p-4">
        <Heading
            title="Importar o calendário da escola"
            description="Carrega a folha de cálculo do calendário publicado pelo agrupamento. Nada é criado agora — vais rever tudo, linha a linha, no passo seguinte."
        />

        <form
            class="space-y-4 rounded-lg border border-border p-4"
            @submit.prevent="submit"
        >
            <div class="grid gap-2">
                <Label for="calendar-file">Ficheiro Excel (.xlsx)</Label>
                <FileInput
                    id="calendar-file"
                    accept=".xlsx"
                    @change="onFileChange"
                />
                <InputError :message="form.errors.calendar" />
                <p class="text-xs text-muted-foreground">
                    É esperada a folha com os meses do ano letivo lado a lado e
                    o quadro de períodos e interrupções por baixo — tal como o
                    agrupamento a publica.
                </p>
                <!--
                    DITO POR PALAVRAS, em vez de aceitar um PDF e falhar de forma
                    confusa. O leitor de Excel foi escrito contra um calendário
                    real; para PDF não há nenhum contra o qual verificar seja o
                    que for, e um importador que se engana em silêncio sobre a
                    estrutura de um ano é pior do que um que ainda não existe.
                -->
                <p class="text-xs text-muted-foreground">
                    Em breve: importação a partir de PDF.
                </p>
            </div>

            <p v-if="academicYear" class="text-sm text-muted-foreground">
                As datas vão ser propostas para o ano letivo
                <span class="font-medium text-foreground">{{
                    academicYear.label
                }}</span>
                . Nenhuma aula é criada ou apagada por esta importação.
            </p>
            <p v-else class="text-sm text-muted-foreground">
                Não há nenhum ano letivo selecionado. Escolhe um ano antes de
                importar um calendário.
            </p>

            <div class="flex items-center gap-2">
                <Button
                    type="submit"
                    :disabled="form.processing || !form.calendar"
                >
                    <FileUp class="size-4" />
                    {{ form.processing ? 'A ler o ficheiro…' : 'Continuar' }}
                </Button>
                <Button as-child variant="ghost">
                    <Link href="/calendar">
                        <ArrowLeft class="size-4" /> Voltar ao Calendário
                    </Link>
                </Button>
            </div>
        </form>
    </div>
</template>
