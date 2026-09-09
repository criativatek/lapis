<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, ref } from 'vue';
import ContextualHelp from '@/components/ContextualHelp.vue';
import FileInput from '@/components/FileInput.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import TableShell from '@/components/TableShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/** One of the students already on the roll that a row could be about. */
type Candidate = {
    enrollment_id: number;
    name: string;
    class_number: number | null;
};

type PreviewRow = {
    name: string;
    class_number: number | null;
    birth_date: string | null;
    situation_code: string | null;
    situation_recognized: boolean;
    /** «Mudou de turma» — the words, not the code. Null when unrecognised. */
    situation_label: string | null;
    /** What the record says today, when the student is already on the roll. */
    current_state: string | null;
    state_changes: boolean;
    process_number: string | null;
    note: string | null;
    photo_index: number | null;
    photo_extension: string | null;
    duplicate_in_file: boolean;
    /** Duas linhas apontam ao MESMO aluno — nomes diferentes, destino igual. */
    duplicate_target: boolean;
    already_enrolled: boolean;
    /** The enrolment this row updates, when the student is already on the roll. */
    enrollment_id: number | null;
    /** `process_number` · `name` · null — how the student was recognised. */
    matched_by: string | null;
    /** More than one student on the roll answers to this row. */
    ambiguous: boolean;
    candidates: Candidate[];
    /** The name on record today, so a correction can be shown as a change. */
    current_name: string | null;
    name_changes: boolean;
    /** Whether that student already has a photo — «associar» vs «substituir». */
    has_photo_today: boolean;
    /** `enrol` · `update` · `skip` · `ambiguous` — decided server-side, never here. */
    action: string;
    include: boolean;
};

// Every photo PhotoFileParser extracted, whether or not it auto-matched a
// row by name — and, since a file exported without «colocar o nome ao lado
// da foto» carries no names at all, whether or not it HAS a name. Those are
// the ones `named: false` marks: they can only ever be assigned by hand.
type PhotoOption = {
    index: number;
    extension: string;
    named: boolean;
};

type HelpArticle = { id: string; title: string; summary: string };

const props = defineProps<{
    schoolClassUlid: string;
    token: string;
    rows: PreviewRow[];
    photos: PhotoOption[];
    /**
     * `roster` — a list of students was uploaded, most of them probably new.
     * `photos` — the class already exists and only its photos are being
     * corrected. Same page, same confirm, same discard: what changes is what
     * the teacher is told they are about to do.
     */
    flow?: string;
    helpArticles?: HelpArticle[];
}>();

const isPhotoCorrection = computed(() => props.flow === 'photos');

// class_number is '' when empty (the backend treats empty as null, via the
// ConvertEmptyStringsToNull middleware); a plain null would not satisfy the
// Input's string|number model type — same convention as classes/Show.vue.
type FormRow = Omit<PreviewRow, 'class_number'> & {
    class_number: number | string;
    photo_temp_path: string | null;
    /**
     * Which student an ambiguous row is about, once the teacher has said.
     * '' until then, 'new' for somebody this class does not have yet, or the
     * enrolment id as a string. Never decided here by default — that is the
     * whole point of marking the row ambiguous in the first place.
     */
    ambiguous_choice: string;
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
        ambiguous_choice: '',
    };
}

const form = useForm<{ rows: FormRow[] }>({
    rows: props.rows.map(toFormRow),
});

// Auto-matching by name (see attachPhotos() server-side) is the normal case —
// the manual picker below stays collapsed by default so a correctly-matched
// row doesn't force the teacher to look at every other photo. It only opens
// on demand, per row, via the "trocar"/"escolher" trigger.
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
        { photos: photosFile.value, rows: form.rows, flow: props.flow ?? 'roster' },
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

                // Numa correção de fotos, uma linha só tem alguma coisa a
                // fazer quando tem uma foto — é o mesmo critério com que o
                // servidor marcou as linhas da primeira leitura. Sem isto,
                // trocar de ficheiro deixava todas as associações novas por
                // marcar, e confirmar não fazia nada.
                if (isPhotoCorrection.value) {
                    form.rows.forEach((row) => {
                        row.include = row.photo_index !== null;
                    });
                }

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

    // Assigning a photo to a row in the photo-correction flow is the teacher
    // saying "this one" — having to tick a second box afterwards would be
    // asking the same question twice. Clearing it un-ticks the row again,
    // because there is then nothing left for that row to do.
    if (isPhotoCorrection.value) {
        row.include = photo !== null;
    }
}

/**
 * Which student an ambiguous row is about, once the teacher has said so.
 *
 * Until they do, the row stays out of the import: two students answering to
 * the same name is not something to resolve by picking the first one. Saying
 * «novo aluno» is a real answer too — a second Maria Silva who is genuinely a
 * second person.
 */
function resolveAmbiguity(rowIndex: number, choice: string): void {
    const row = form.rows[rowIndex];
    row.ambiguous_choice = choice;

    if (choice === '') {
        row.enrollment_id = null;
        row.include = false;

        return;
    }

    row.enrollment_id = choice === 'new' ? null : Number(choice);
    row.include = true;
}

function normalizeName(value: string): string {
    return value.trim().replace(/\s+/g, ' ').toLowerCase();
}

// Computed from the LIVE row, not from the server's own name_changes flag:
// the teacher can correct a name right here, and the summary has to describe
// what confirming would actually do, not what the file happened to say.
function hasNameChange(row: FormRow): boolean {
    return (
        row.current_name !== null &&
        row.name.trim() !== '' &&
        normalizeName(row.current_name) !== normalizeName(row.name)
    );
}

function isNewStudent(row: FormRow): boolean {
    return row.enrollment_id === null && !row.ambiguous;
}

/**
 * NOTHING IS APPLIED BEFORE THE FINAL CONFIRMATION (§6), so this is the only
 * account of it the teacher gets beforehand — and it is live: every count
 * follows the ticks, the name corrections and the photo assignments as they
 * are made, not as the file arrived.
 */
const summary = computed(() => {
    const included = form.rows.filter((row) => row.include);

    return {
        recognized: form.rows.filter((row) => row.already_enrolled).length,
        newStudents: included.filter(isNewStudent).length,
        nameUpdates: included.filter(hasNameChange).length,
        photosAdded: included.filter(
            (row) => row.photo_index !== null && !row.has_photo_today,
        ).length,
        photosReplaced: included.filter(
            (row) => row.photo_index !== null && row.has_photo_today,
        ).length,
        ambiguous: form.rows.filter(
            (row) => row.ambiguous && row.ambiguous_choice === '',
        ).length,
        duplicates: form.rows.filter(
            (row) => row.duplicate_in_file || row.duplicate_target,
        ).length,
        skipped: form.rows.filter((row) => !row.include).length,
    };
});

const assignedPhotoCount = computed(
    () => form.rows.filter((row) => row.photo_index !== null).length,
);

const unnamedPhotoCount = computed(
    () => props.photos.filter((photo) => !photo.named).length,
);

const unassignedPhotoCount = computed(
    () => props.photos.length - assignedPhotoCount.value,
);

function submit(): void {
    form.post(
        `/classes/${props.schoolClassUlid}/roster-imports/${props.token}/confirm`,
    );
}

const discardHref = computed(
    () =>
        `/classes/${props.schoolClassUlid}/roster-imports/${props.token}?flow=${props.flow ?? 'roster'}`,
);

// `limit` (App\Support\Limits\Limits::assertCanIncreaseFor, tripped by a
// reactivation via fillFromRoster()) is never a field of this form — read
// through a string index the same way academic-years/Form.vue does for its
// own dynamic error keys.
const limitError = computed(() => (form.errors as Record<string, string>).limit);
</script>

<template>
    <Head
        :title="
            isPhotoCorrection
                ? 'Corrigir fotos da turma'
                : 'Pré-visualização da importação'
        "
    />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <Heading
            :title="
                isPhotoCorrection ? 'Corrigir fotos' : 'Confirmar importação'
            "
            :description="
                isPhotoCorrection
                    ? 'Estes são os alunos que já estão nesta turma. Atribui uma foto a cada um e confirma no fim. Nenhum aluno é eliminado, criado ou alterado nas avaliações.'
                    : 'Revê cada aluno antes de inscrever. Desmarca uma linha para a excluir.'
            "
        />
        <ContextualHelp :articles="helpArticles" />

        <!-- O QUE VAI ACONTECER, ANTES DE ACONTECER (§6). Contas ao vivo:
             seguem as marcações, as correções de nome e as fotos atribuídas
             à medida que são feitas, e não o que o ficheiro trazia. -->
        <div class="rounded-lg border border-border bg-muted/40 p-4">
            <h2 class="text-sm font-semibold">Antes de confirmar</h2>
            <dl
                class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-3"
            >
                <div class="flex justify-between gap-2">
                    <dt class="text-muted-foreground">Já nesta turma</dt>
                    <dd class="font-medium">{{ summary.recognized }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-muted-foreground">A inscrever</dt>
                    <dd class="font-medium">{{ summary.newStudents }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-muted-foreground">Nomes a corrigir</dt>
                    <dd class="font-medium">{{ summary.nameUpdates }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-muted-foreground">Fotos a associar</dt>
                    <dd class="font-medium">{{ summary.photosAdded }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-muted-foreground">Fotos a substituir</dt>
                    <dd class="font-medium">{{ summary.photosReplaced }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-muted-foreground">Por decidir</dt>
                    <dd
                        class="font-medium"
                        :class="summary.ambiguous > 0 ? 'text-amber-700 dark:text-amber-300' : ''"
                    >
                        {{ summary.ambiguous }}
                    </dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-muted-foreground">Duplicados no ficheiro</dt>
                    <dd class="font-medium">{{ summary.duplicates }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-muted-foreground">Linhas ignoradas</dt>
                    <dd class="font-medium">{{ summary.skipped }}</dd>
                </div>
                <div v-if="photos.length" class="flex justify-between gap-2">
                    <dt class="text-muted-foreground">Fotos por atribuir</dt>
                    <dd class="font-medium">{{ unassignedPhotoCount }}</dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-muted-foreground">
                Nada é escrito até confirmares. Avaliações, registos,
                intervenções e relatórios não são tocados por esta operação.
            </p>
        </div>

        <!-- O caso do 7.º B, dito por palavras: o ficheiro foi exportado sem a
             opção «colocar o nome ao lado da foto», por isso as fotos vêm sem
             nome nenhum e não há por onde as associar automaticamente. Vêm à
             mesma — e atribuem-se à mão, aqui. -->
        <p
            v-if="unnamedPhotoCount > 0"
            class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
        >
            {{ unnamedPhotoCount }} de {{ photos.length }} fotos deste ficheiro
            não trazem nome — o ficheiro terá sido exportado sem a opção
            «colocar o nome ao lado da foto». Não são associadas
            automaticamente, para não adivinhar de quem são: usa «escolher» na
            coluna Foto para atribuir cada uma.
        </p>

        <div class="space-y-6">
            <TableShell>
                <template #head>
                    <tr>
                        <th class="px-3 py-2.5 font-medium">Incluir</th>
                        <th class="px-3 py-2.5 font-medium">Foto</th>
                        <th class="px-3 py-2.5 font-medium">Nome</th>
                        <th class="px-3 py-2.5 font-medium">Nº</th>
                        <th
                            v-if="!isPhotoCorrection"
                            class="px-3 py-2.5 font-medium"
                        >
                            Data nasc.
                        </th>
                        <th
                            v-if="!isPhotoCorrection"
                            class="px-3 py-2.5 font-medium"
                        >
                            Nota
                        </th>
                        <th class="px-3 py-2.5 font-medium">Avisos</th>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="(row, index) in form.rows" :key="index">
                            <td class="px-3 py-2.5">
                                <input
                                    v-model="row.include"
                                    type="checkbox"
                                    :disabled="
                                        row.ambiguous &&
                                        row.ambiguous_choice === ''
                                    "
                                />
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
                                <!-- «Nome atual → Nome novo» (§5): a mesma
                                     pessoa com a grafia corrigida, e não um
                                     segundo aluno. -->
                                <p
                                    v-if="hasNameChange(row)"
                                    class="mt-1 text-xs text-amber-800 dark:text-amber-300"
                                >
                                    {{ row.current_name }} → {{ row.name }}
                                </p>
                            </td>
                            <td class="px-3 py-2.5">
                                <Input
                                    v-model.number="row.class_number"
                                    type="number"
                                    class="h-8 w-16"
                                />
                            </td>
                            <td
                                v-if="!isPhotoCorrection"
                                class="px-3 py-2.5 text-muted-foreground"
                            >
                                {{ row.birth_date ?? '—' }}
                            </td>
                            <td
                                v-if="!isPhotoCorrection"
                                class="px-3 py-2.5 text-muted-foreground"
                            >
                                {{ row.note ?? '—' }}
                            </td>
                            <td class="px-3 py-2.5">
                                <!-- DOIS ALUNOS RESPONDEM A ESTA LINHA. Não se
                                     escolhe por eles: enquanto não for dito
                                     qual, a linha fica de fora (§3). -->
                                <div v-if="row.ambiguous" class="space-y-1">
                                    <Badge
                                        variant="outline"
                                        class="border-amber-300 text-amber-900 dark:border-amber-800 dark:text-amber-200"
                                        >mais do que um aluno com este nome —
                                        escolhe qual</Badge
                                    >
                                    <select
                                        class="h-8 w-full rounded-md border border-border bg-background px-2 text-xs"
                                        :value="row.ambiguous_choice"
                                        @change="
                                            resolveAmbiguity(
                                                index,
                                                ($event.target as HTMLSelectElement)
                                                    .value,
                                            )
                                        "
                                    >
                                        <option value="">Por decidir</option>
                                        <option
                                            v-for="candidate in row.candidates"
                                            :key="candidate.enrollment_id"
                                            :value="String(candidate.enrollment_id)"
                                        >
                                            {{
                                                candidate.class_number
                                                    ? `n.º ${candidate.class_number} — `
                                                    : ''
                                            }}{{ candidate.name }}
                                        </option>
                                        <option value="new">
                                            É um aluno novo
                                        </option>
                                    </select>
                                </div>

                                <Badge
                                    v-if="row.duplicate_in_file"
                                    variant="outline"
                                    >nome duplicado no ficheiro</Badge
                                >
                                <!-- Nomes diferentes, aluno igual: quase sempre
                                     o mesmo n.º de processo escrito em duas
                                     linhas. Dizer «nome duplicado» aqui mandava
                                     o professor procurar uma repetição que não
                                     existe. -->
                                <Badge
                                    v-if="row.duplicate_target"
                                    variant="outline"
                                    class="border-amber-300 text-amber-900 dark:border-amber-800 dark:text-amber-200"
                                    >duas linhas para o mesmo aluno — nenhuma
                                    entra</Badge
                                >
                                <!-- Already on the roll: the roster fills in what
                                     the record is missing and erases nothing. -->
                                <Badge
                                    v-if="row.already_enrolled"
                                    variant="outline"
                                >
                                    {{
                                        row.matched_by === 'process_number'
                                            ? 'já nesta turma — reconhecido pelo n.º de processo'
                                            : 'já nesta turma — atualiza os dados em falta'
                                    }}
                                </Badge>
                                <!-- «associar» e «substituir» não são a mesma
                                     coisa, e a diferença diz-se antes (§4). -->
                                <Badge
                                    v-if="
                                        row.photo_index !== null &&
                                        row.has_photo_today
                                    "
                                    variant="outline"
                                    class="border-amber-300 text-amber-900 dark:border-amber-800 dark:text-amber-200"
                                    >substitui a foto atual</Badge
                                >
                                <!-- THE CODE READ, NOT THE CODE SHOWN. «MT» on
                                     a roll means «Mudou de turma», and a
                                     teacher should not have to know the
                                     abbreviation to check the import (§12). -->
                                <Badge v-if="row.situation_label" variant="outline">
                                    {{ row.situation_code }} — {{ row.situation_label }}
                                </Badge>

                                <!-- Only when the roll asks for something other
                                     than what the record already says. -->
                                <Badge
                                    v-if="row.state_changes"
                                    variant="outline"
                                    class="border-amber-300 text-amber-900 dark:border-amber-800 dark:text-amber-200"
                                >
                                    {{ row.current_state }} → {{ row.situation_label }}
                                </Badge>

                                <Badge
                                    v-if="
                                        !isPhotoCorrection &&
                                        !row.situation_recognized
                                    "
                                    variant="outline"
                                    class="border-amber-300 text-amber-900 dark:border-amber-800 dark:text-amber-200"
                                >
                                    <template v-if="row.situation_code">
                                        situação "{{ row.situation_code }}" não reconhecida — o estado
                                        da matrícula fica como está
                                    </template>
                                    <template v-else>
                                        sem situação no ficheiro — o estado da matrícula fica como está
                                    </template>
                                </Badge>
                            </td>
                        </tr>
                </template>
            </TableShell>

            <div class="space-y-3 rounded-lg border border-dashed border-border p-4">
                <h2 class="text-sm font-semibold">
                    {{
                        isPhotoCorrection
                            ? 'Usar outro ficheiro de fotos'
                            : 'Adicionar fotos'
                    }}
                </h2>
                <p class="text-xs text-muted-foreground">
                    Ficheiro Word exportado do Intuitivo (modelo EB019) com as
                    fotos dos alunos. As fotos com nome são associadas por nome
                    às linhas acima — inclui primeiro quaisquer correções de
                    nome que já tenhas feito. As fotos sem nome ficam
                    disponíveis para atribuíres à mão. Podes trocar de ficheiro
                    aqui quantas vezes precisares; nada é escrito até
                    confirmares.
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
                        {{
                            isPhotoCorrection
                                ? 'Ler este ficheiro'
                                : 'Adicionar fotos'
                        }}
                    </Button>
                </div>
                <InputError :message="photosError ?? undefined" />
            </div>

            <p
                v-if="photosFile"
                class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
            >
                Escolheste um ficheiro de fotos mas ainda não o adicionaste —
                clica no botão acima antes de confirmar, ou os alunos ficam sem
                foto.
            </p>

            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-3">
                    <Button
                        type="button"
                        :disabled="form.processing || !!photosFile"
                        @click="submit"
                        >{{
                            isPhotoCorrection
                                ? 'Confirmar correção'
                                : 'Confirmar importação'
                        }}</Button
                    >
                    <!-- A OUTRA SAÍDA, e a razão desta fatia: até aqui só se
                         podia confirmar, e quem trouxesse o ficheiro errado
                         saía pelo botão «anterior» do browser. Mesmo padrão da
                         pré-visualização do horário — mas um DELETE em vez de
                         um link, porque sair daqui apaga mesmo a pasta
                         temporária deste token. Não inscreve ninguém e não
                         altera nada do que já existe na turma. -->
                    <Button as-child variant="ghost" :disabled="form.processing">
                        <Link
                            :href="discardHref"
                            method="delete"
                            as="button"
                            type="button"
                        >
                            <ArrowLeft class="size-4" /> Escolher outro ficheiro
                        </Link>
                    </Button>
                    <span class="text-sm text-muted-foreground">
                        {{ form.rows.filter((r) => r.include).length }} de
                        {{ form.rows.length }} linhas selecionadas.
                    </span>
                </div>
                <InputError :message="limitError" />
            </div>
        </div>
    </div>
</template>
