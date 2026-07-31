<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import FileInput from '@/components/FileInput.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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

// Shared by the initial form seed AND by re-seeding form.rows after
// attachPhotos() responds (see submitPhotos() below) — kept in one place so
// the photo_temp_path/shape construction never drifts between the two.
function toFormRow(row: PreviewRow): FormRow {
    return {
        ...row,
        class_number: row.class_number ?? '',
        photo_temp_path:
            row.photo_index !== null
                ? `roster-imports/${props.token}/${row.photo_index}.${row.photo_extension}`
                : null,
    };
}

const form = useForm<{ rows: FormRow[] }>({
    rows: props.rows.map(toFormRow),
});

// Auto-matching by name (see attachPhotos() server-side) is the normal case —
// the manual picker below stays collapsed by default so a correctly-matched
// row doesn't force the teacher to look at every other photo. It only opens
// on demand, per row, via the "Trocar foto"/"escolher" trigger.
const photoPickerOpen = ref<boolean[]>(props.rows.map(() => false));

const photosFile = ref<File | null>(null);
const photosProcessing = ref(false);
const photosError = ref<string | null>(null);

function onPhotosFileChange(event: Event): void {
    photosFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    photosError.value = null;
}

// A separate, later phase from the roster upload (classes/Show.vue): the
// teacher reviews/edits the roster first, THEN optionally attaches photos —
// so this submits form.rows (the CURRENT, possibly-edited row data) rather
// than relying on any server-side memory of the original upload.
function submitPhotos(): void {
    if (!photosFile.value) {
        return;
    }

    photosProcessing.value = true;

    router.post(
        `/classes/${props.schoolClassUlid}/roster-imports/${props.token}/photos`,
        { photos: photosFile.value, rows: form.rows },
        {
            forceFormData: true,
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                // Inertia updates props.rows/props.photos reactively, but
                // form.rows (this useForm's own local copy) does not
                // automatically re-derive from updated props — it must be
                // re-seeded explicitly, through the same toFormRow() used
                // on initial load.
                form.rows = props.rows.map(toFormRow);
                photosFile.value = null;
            },
            onError: (errors) => {
                photosError.value = errors.photos ?? null;
            },
            onFinish: () => {
                photosProcessing.value = false;
            },
        },
    );
}

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

        <div class="space-y-6">
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
                                <Collapsible v-model:open="photoPickerOpen[index]">
                                    <div class="flex items-center gap-2">
                                        <img
                                            v-if="photoUrl(row.photo_index)"
                                            :src="photoUrl(row.photo_index)!"
                                            :alt="row.name"
                                            class="size-8 shrink-0 rounded-full object-cover"
                                        />
                                        <span
                                            v-else
                                            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-[9px] text-muted-foreground"
                                            >sem foto</span
                                        >
                                        <CollapsibleTrigger as-child>
                                            <button
                                                type="button"
                                                class="text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                                            >
                                                {{
                                                    row.photo_index === null
                                                        ? 'escolher'
                                                        : 'trocar'
                                                }}
                                            </button>
                                        </CollapsibleTrigger>
                                    </div>

                                    <!-- Manual photo assignment/correction: collapsed by default,
                                         since auto-matching by name already handles the normal
                                         case (attachPhotos() server-side). Click a thumbnail to
                                         assign that photo to this row, or "Ø" to clear it. Only
                                         photos not already claimed by ANOTHER row are offered
                                         here (availablePhotosFor), so two rows can never end up
                                         pointing at the same photo. -->
                                    <CollapsibleContent
                                        class="mt-1.5 flex max-w-64 flex-wrap gap-1"
                                    >
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
                                    </CollapsibleContent>
                                </Collapsible>
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

            <div class="space-y-3 rounded-lg border border-dashed border-border p-4">
                <h2 class="text-sm font-semibold">Adicionar fotos</h2>
                <p class="text-xs text-muted-foreground">
                    Ficheiro Word exportado do Intuitivo (modelo EB019) com as
                    fotos dos alunos. As fotos são associadas por nome às linhas acima —
                    inclui primeiro quaisquer correções de nome que já tenhas
                    feito. Faz isto antes de confirmar: depois de confirmada a
                    importação já não é possível associar fotos aqui.
                </p>
                <div class="flex flex-wrap items-end gap-3">
                    <div class="grid gap-2">
                        <Label for="photos-file">Ficheiro Word (fotos)</Label>
                        <FileInput
                            id="photos-file"
                            accept=".doc,.docx"
                            @change="onPhotosFileChange"
                        />
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="!photosFile || photosProcessing"
                        @click="submitPhotos"
                    >
                        Adicionar fotos
                    </Button>
                </div>
                <InputError :message="photosError ?? undefined" />
            </div>

            <p
                v-if="photosFile"
                class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
            >
                Escolheste um ficheiro de fotos mas ainda não o adicionaste —
                clica em "Adicionar fotos" acima antes de confirmar, ou os
                alunos ficam sem foto.
            </p>

            <div class="flex items-center gap-3">
                <Button
                    type="button"
                    :disabled="form.processing || !!photosFile"
                    @click="submit"
                    >Confirmar importação</Button
                >
                <span class="text-sm text-muted-foreground">
                    {{ form.rows.filter((r) => r.include).length }} de
                    {{ form.rows.length }} serão inscritos.
                </span>
            </div>
        </div>
    </div>
</template>
