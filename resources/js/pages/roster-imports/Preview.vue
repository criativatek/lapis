<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

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

const props = defineProps<{
    schoolClassUlid: string;
    token: string;
    rows: PreviewRow[];
}>();

// class_number is '' when empty (the backend treats empty as null, via the
// ConvertEmptyStringsToNull middleware); a plain null would not satisfy the
// Input's string|number model type — same convention as classes/Show.vue.
type FormRow = Omit<PreviewRow, 'class_number'> & {
    class_number: number | string;
    photo_temp_path: string | null;
};

const form = useForm<{ rows: FormRow[] }>({
    rows: props.rows.map((row) => ({
        ...row,
        class_number: row.class_number ?? '',
        photo_temp_path:
            row.photo_index !== null
                ? `roster-imports/${props.token}/${row.photo_index}.${row.photo_extension}`
                : null,
    })),
});

function photoUrl(index: number | null): string | null {
    if (index === null) {
        return null;
    }

    return `/classes/${props.schoolClassUlid}/roster-imports/${props.token}/photos/${index}`;
}

function submit(): void {
    form.post(
        `/classes/${props.schoolClassUlid}/roster-imports/${props.token}/confirm`,
    );
}
</script>

<template>
    <Head title="Pré-visualização da importação" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <Heading
            title="Confirmar importação"
            description="Revê cada aluno antes de inscrever. Desmarca uma linha para a excluir."
        />

        <form class="space-y-4" @submit.prevent="submit">
            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2.5 font-medium">Incluir</th>
                            <th class="px-3 py-2.5 font-medium">Foto</th>
                            <th class="px-3 py-2.5 font-medium">Nome</th>
                            <th class="px-3 py-2.5 font-medium">Nº</th>
                            <th class="px-3 py-2.5 font-medium">Data nasc.</th>
                            <th class="px-3 py-2.5 font-medium">Nota</th>
                            <th class="px-3 py-2.5 font-medium">Avisos</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="(row, index) in form.rows" :key="index">
                            <td class="px-3 py-2.5">
                                <input v-model="row.include" type="checkbox" />
                            </td>
                            <td class="px-3 py-2.5">
                                <img
                                    v-if="photoUrl(row.photo_index)"
                                    :src="photoUrl(row.photo_index)!"
                                    :alt="row.name"
                                    class="size-8 rounded-full object-cover"
                                />
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >sem foto</span
                                >
                            </td>
                            <td class="px-3 py-2.5">
                                <Input v-model="row.name" class="h-8" />
                            </td>
                            <td class="px-3 py-2.5">
                                <Input
                                    v-model.number="row.class_number"
                                    type="number"
                                    class="h-8 w-16"
                                />
                            </td>
                            <td class="px-3 py-2.5 text-muted-foreground">
                                {{ row.birth_date ?? '—' }}
                            </td>
                            <td class="px-3 py-2.5 text-muted-foreground">
                                {{ row.note ?? '—' }}
                            </td>
                            <td class="px-3 py-2.5">
                                <Badge
                                    v-if="row.duplicate_in_file"
                                    variant="outline"
                                    >nome duplicado no ficheiro</Badge
                                >
                                <Badge
                                    v-if="row.already_enrolled"
                                    variant="outline"
                                    >já inscrito nesta turma</Badge
                                >
                                <Badge
                                    v-if="!row.situation_recognized"
                                    variant="outline"
                                >
                                    situação "{{ row.situation_code }}" não
                                    reconhecida — entra como Inscrito
                                </Badge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing"
                    >Confirmar importação</Button
                >
                <span class="text-sm text-muted-foreground">
                    {{ form.rows.filter((r) => r.include).length }} de
                    {{ form.rows.length }} serão inscritos.
                </span>
            </div>
        </form>
    </div>
</template>
