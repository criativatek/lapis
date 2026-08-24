<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, FileUp } from '@lucide/vue';
import FileInput from '@/components/FileInput.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

defineProps<{
    academicYear: string | null;
}>();

const form = useForm<{ timetable: File | null }>({ timetable: null });

function onFileChange(event: Event): void {
    form.timetable = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submit(): void {
    form.post('/timetable-imports', { forceFormData: true });
}
</script>

<template>
    <Head title="Importar horário" />

    <div class="mx-auto w-full max-w-2xl space-y-6 p-4">
        <Heading
            title="Importar horário do professor"
            description="Carrega o PDF do teu horário exportado pela escola. Nada é criado agora — vais rever tudo no passo seguinte."
        />

        <form
            class="space-y-4 rounded-lg border border-border p-4"
            @submit.prevent="submit"
        >
            <div class="grid gap-2">
                <Label for="timetable-file">Ficheiro PDF</Label>
                <FileInput
                    id="timetable-file"
                    accept=".pdf,application/pdf"
                    @change="onFileChange"
                />
                <InputError :message="form.errors.timetable" />
                <p class="text-xs text-muted-foreground">
                    O horário tem de ser o PDF exportado pela escola, com texto.
                    Uma digitalização ou uma fotografia do horário não pode ser
                    lida.
                </p>
            </div>

            <p v-if="academicYear" class="text-sm text-muted-foreground">
                As aulas vão ser associadas às tuas turmas de
                <span class="font-medium text-foreground">{{
                    academicYear
                }}</span
                >.
            </p>

            <div class="flex items-center gap-2">
                <Button
                    type="submit"
                    :disabled="form.processing || !form.timetable"
                >
                    <FileUp class="size-4" />
                    {{ form.processing ? 'A ler o ficheiro…' : 'Continuar' }}
                </Button>
                <Button as-child variant="ghost">
                    <Link href="/classes/schedule-setup">
                        <ArrowLeft class="size-4" /> Voltar a Configurar horários
                    </Link>
                </Button>
            </div>
        </form>
    </div>
</template>
