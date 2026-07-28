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

// Every photo PhotoFileParser extracted, whether or not it auto-matched a
// row by name — the teacher can assign any of these to any row (below).
type PhotoOption = {
    index: number;
    extension: string;
};

const props = defineProps<{
    schoolClassUlid: string;
    token: string;
    rows: PreviewRow[];
    photos: PhotoOption[];
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

// A photo is offered as an option for row R if no OTHER row currently has it
// assigned — or if it is the one already assigned to row R itself. This
// prevents two rows from ever claiming the same photo: assigning it to a new
// row instantly removes it from every other row's option list.
function availablePhotosFor(rowIndex: number): PhotoOption[] {
    return props.photos.filter(
        (photo) =>
            !form.rows.some(
                (otherRow, otherIndex) =>
                    otherIndex !== rowIndex &&
                    otherRow.photo_index === photo.index,
            ),
    );
}

function assignPhoto(rowIndex: number, photo: PhotoOption | null): void {
    const row = form.rows[rowIndex];
    row.photo_index = photo?.index ?? null;
    row.photo_extension = photo?.extension ?? null;
    row.photo_temp_path = photo
        ? `roster-imports/${props.token}/${photo.index}.${photo.extension}`
        : null;
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
                                <div class="flex flex-col gap-1.5">
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

                                    <!-- Manual photo assignment/correction: click a thumbnail to
                                         assign that photo to this row, or "nenhuma" to clear it.
                                         Only photos not already claimed by ANOTHER row are offered
                                         here (availablePhotosFor), so two rows can never end up
                                         pointing at the same photo. -->
                                    <div class="flex flex-wrap gap-1">
                                        <button
                                            type="button"
                                            class="flex size-6 shrink-0 items-center justify-center rounded border text-[10px] text-muted-foreground"
                                            :class="
                                                row.photo_index === null
                                                    ? 'border-primary ring-1 ring-primary'
                                                    : 'border-border'
                                            "
                                            title="Sem foto"
                                            @click="assignPhoto(index, null)"
                                        >
                                            Ø
                                        </button>
                                        <button
                                            v-for="photo in availablePhotosFor(
                                                index,
                                            )"
                                            :key="photo.index"
                                            type="button"
                                            class="size-6 shrink-0 overflow-hidden rounded border"
                                            :class="
                                                row.photo_index === photo.index
                                                    ? 'border-primary ring-1 ring-primary'
                                                    : 'border-border'
                                            "
                                            :title="`Atribuir foto ${photo.index + 1}`"
                                            @click="assignPhoto(index, photo)"
                                        >
                                            <img
                                                :src="photoUrl(photo.index)!"
                                                :alt="`Foto ${photo.index + 1}`"
                                                class="size-full object-cover"
                                            />
                                        </button>
                                    </div>
                                </div>
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
