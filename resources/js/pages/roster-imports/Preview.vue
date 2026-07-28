<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';

/**
 * Minimal placeholder for the roster-import review table (Task 8 only wires
 * the backend upload/preview endpoint). The real reviewable, editable grid —
 * matched photos, duplicate/already-enrolled flags, per-row include toggle,
 * and the confirm action — is built in a later frontend task; this exists so
 * the upload endpoint has somewhere real to render to in the meantime.
 */
type PreviewRow = {
    name: string;
    class_number: number | null;
    birth_date: string | null;
    situation_code: string;
    situation_recognized: boolean;
    process_number: string | null;
    note: string | null;
    photo_index: number | null;
    photo_extension: string | null;
    duplicate_in_file: boolean;
    already_enrolled: boolean;
    include: boolean;
};

defineProps<{
    schoolClassUlid: string;
    token: string;
    rows: PreviewRow[];
}>();
</script>

<template>
    <Head title="Importar turma — pré-visualização" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            title="Pré-visualização da importação"
            description="Confirma os alunos antes de os adicionar à turma."
        />

        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-muted-foreground">
                    <th class="py-2 font-medium">Nome</th>
                    <th class="py-2 font-medium">Situação</th>
                    <th class="py-2 font-medium">Nota</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(row, index) in rows"
                    :key="index"
                    class="border-b last:border-0"
                >
                    <td class="py-2">{{ row.name }}</td>
                    <td class="py-2">{{ row.situation_code }}</td>
                    <td class="py-2">{{ row.note }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
