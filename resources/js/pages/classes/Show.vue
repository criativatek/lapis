<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { FileUp, Footprints, Pencil, Trash2, UserPlus } from '@lucide/vue';
import { computed, ref } from 'vue';
import FileInput from '@/components/FileInput.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import LessonScheduleEditor from '@/components/lessons/LessonScheduleEditor.vue';
import type { RecurringLessonSlot } from '@/components/lessons/LessonScheduleEditor.vue';
import StudentAvatar from '@/components/StudentAvatar.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { preparePhotoForUpload } from '@/lib/studentPhoto';

type Student = {
    ulid: string;
    name: string;
    has_identity: boolean;
    pseudonym: string;
    class_number: number | null;
    /** The school's own identifier for this student. Optional, and often absent. */
    process_number: string | null;
    enrolled_on: string;
    is_late_entry: boolean;
    status_label: string;
    photo_url: string | null;
};

type ProfileOption = { version_id: number; label: string };

const props = defineProps<{
    schoolClass: {
        id: number;
        ulid: string;
        label: string;
        subject: string;
        academic_year: string;
        grade_level: string | null;
        status: string;
        status_label: string;
        profile_name: string | null;
    };
    students: Student[];
    /** Students who were on this roll and are no longer part of the class. */
    former_students: {
        ulid: string;
        name: string;
        class_number: number | null;
        /** «Mudou de turma» — why they left, in words. */
        state_label: string;
    }[];
    availableProfiles: ProfileOption[];
    recurringLessonSlots: RecurringLessonSlot[] | null;
}>();

/**
 * «Acompanhamento» straight from the roll — the same (turma, inscrição) pair
 * `student-progress.student` has always answered at, and nothing else: no new
 * route, no new payload, no reading computed here. The class page is where a
 * teacher already has the roll in front of them, and having to go back out to
 * «Acompanhamento → Aluno» to pick the same turma again was the detour.
 *
 * Presentation only, and `canRead` rather than `modules` so a suspended
 * organization — whose data stays consultable — keeps the link. The route's own
 * `module:student_progress` gate is the actual authority.
 */
const canFollowUp = computed(
    () =>
        usePage().props.modules.includes('student_progress') ||
        usePage().props.readOnlyModules.includes('student_progress'),
);

/**
 * N.os de processo, by enrolment. Seeded from what is stored, edited in place,
 * and sent as one column — which is how a teacher has them.
 */
const processNumbers = ref<Record<string, string>>(
    Object.fromEntries(
        props.students.map((student) => [
            student.ulid,
            student.process_number ?? '',
        ]),
    ),
);

const missingProcessNumbers = computed(
    () =>
        props.students.filter(
            (student) => (student.process_number ?? '') === '',
        ).length,
);

const processNumberForm = useForm({});

function saveProcessNumbers(): void {
    processNumberForm
        .transform(() => ({
            numbers: props.students.map((student) => ({
                enrollment_ulid: student.ulid,
                // An empty field is «no number recorded», which is a real state.
                process_number:
                    processNumbers.value[student.ulid]?.trim() || null,
            })),
        }))
        .put(`/classes/${props.schoolClass.ulid}/process-numbers`, {
            preserveScroll: true,
        });
}

const profileForm = useForm<{ assessment_profile_version_id: number | null }>({
    assessment_profile_version_id: null,
});

function assignProfile(): void {
    profileForm.put(`/classes/${props.schoolClass.ulid}/profile`, {
        preserveScroll: true,
    });
}

function activateClass(): void {
    router.post(
        `/classes/${props.schoolClass.ulid}/activate`,
        {},
        {
            preserveScroll: true,
        },
    );
}

// class_number is '' when empty (the backend treats empty as null); a plain
// null would not satisfy the Input's string|number model type.
const form = useForm<{
    name: string;
    class_number: number | string;
    enrolled_on: string;
}>({
    name: '',
    class_number: '',
    enrolled_on: '',
});

function enroll(): void {
    form.post(`/classes/${props.schoolClass.ulid}/students`, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

// `limit` (App\Support\Limits\Limits::assertCanIncreaseFor) is never a field
// of the enrollment form — read through a string index the same way
// academic-years/Form.vue does for its own dynamic error keys.
const enrollmentLimitError = computed(() => (form.errors as Record<string, string>).limit);

// Correcting a student already enrolled. Kept separate from the enrollment
// form above so an open correction never clobbers a half-typed new student.
const editDialogOpen = ref(false);
const editingUlid = ref<string | null>(null);

// Derived from the props rather than held as a copy: managing the photo
// reloads the page data, and the open dialog has to show the new photo.
const editingStudent = computed<Student | null>(
    () => props.students.find((s) => s.ulid === editingUlid.value) ?? null,
);

const editForm = useForm<{
    name: string;
    class_number: number | string;
    enrolled_on: string;
}>({
    name: '',
    class_number: '',
    enrolled_on: '',
});

function openEdit(student: Student): void {
    editingUlid.value = student.ulid;
    editForm.clearErrors();
    // A student imported without an identity has no name to offer — the field
    // starts empty rather than pre-filled with the "(sem identidade)" marker.
    editForm.name = student.has_identity ? student.name : '';
    editForm.class_number = student.class_number ?? '';
    editForm.enrolled_on = student.enrolled_on;
    editDialogOpen.value = true;
}

function submitEdit(): void {
    if (editingUlid.value === null) {
        return;
    }

    editForm.put(
        `/classes/${props.schoolClass.ulid}/students/${editingUlid.value}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                editDialogOpen.value = false;
                editingUlid.value = null;
            },
        },
    );
}

// The photo is its own request, sent as soon as a file is chosen: an upload and
// a data edit fail in different ways, and a rejected image must not throw away
// a name the teacher has just corrected.
const photoInput = ref<HTMLInputElement | null>(null);
const studentPhotoForm = useForm<{ photo: File | null }>({ photo: null });

async function onStudentPhotoChange(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    if (file === null || editingUlid.value === null) {
        return;
    }

    studentPhotoForm.clearErrors();

    // Validated and, where possible, shrunk client-side first — most phone
    // photos are far bigger than an avatar needs, and this is what keeps a
    // 12 MB original from ever reaching the network at all. The backend
    // still validates everything again on arrival; this only saves a round
    // trip for the common case.
    const prepared = await preparePhotoForUpload(file);

    if (!prepared.ok) {
        studentPhotoForm.setError('photo', prepared.message);

        if (photoInput.value) {
            photoInput.value.value = '';
        }

        return;
    }

    studentPhotoForm.photo = prepared.file;
    studentPhotoForm.post(
        `/classes/${props.schoolClass.ulid}/students/${editingUlid.value}/photo`,
        {
            forceFormData: true,
            preserveScroll: true,
            // Always clear the file input, so picking the same file again after
            // a rejection still fires a change event.
            onFinish: () => {
                studentPhotoForm.photo = null;

                if (photoInput.value) {
                    photoInput.value.value = '';
                }
            },
        },
    );
}

function removeStudentPhoto(): void {
    if (editingUlid.value === null) {
        return;
    }

    if (!confirm('Remover a fotografia deste aluno?')) {
        return;
    }

    router.delete(
        `/classes/${props.schoolClass.ulid}/students/${editingUlid.value}/photo`,
        { preserveScroll: true },
    );
}

function remove(student: Student): void {
    if (confirm(`Remover ${student.name} da turma?`)) {
        router.delete(
            `/classes/${props.schoolClass.ulid}/students/${student.ulid}`,
            { preserveScroll: true },
        );
    }
}

const importDialogOpen = ref(false);
const importForm = useForm<{ roster: File | null }>({
    roster: null,
});

function openImportDialog(): void {
    importForm.reset();
    importForm.clearErrors();
    importDialogOpen.value = true;
}

function onRosterFileChange(event: Event): void {
    importForm.roster = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submitImport(): void {
    importForm.post(`/classes/${props.schoolClass.ulid}/roster-imports`, {
        forceFormData: true,
    });
}

const photoDialogOpen = ref(false);
const photoForm = useForm<{ photos: File | null }>({
    photos: null,
});

function openPhotoDialog(): void {
    photoForm.reset();
    photoForm.clearErrors();
    photoDialogOpen.value = true;
}

function onPhotosFileChange(event: Event): void {
    photoForm.photos = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submitPhotos(): void {
    photoForm.post(`/classes/${props.schoolClass.ulid}/photos`, {
        forceFormData: true,
        onSuccess: () => {
            photoDialogOpen.value = false;
        },
    });
}
</script>

<template>
    <Head :title="schoolClass.label" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <div class="flex items-start justify-between gap-3">
            <Heading
                :title="schoolClass.label"
                :description="`${schoolClass.subject} · ${schoolClass.academic_year}`"
            />
            <div class="flex items-center gap-2">
                <Button
                    v-if="schoolClass.status === 'preparation'"
                    type="button"
                    size="sm"
                    @click="activateClass"
                >
                    Ativar turma
                </Button>
                <Button as-child variant="outline" size="sm">
                    <Link
                        :href="`/classes/${schoolClass.ulid}/edit`"
                        :aria-label="`Editar turma ${schoolClass.label}`"
                    >
                        <Pencil class="size-4" /> Editar turma
                    </Link>
                </Button>
                <Badge variant="secondary">{{
                    schoolClass.status_label
                }}</Badge>
            </div>
        </div>

        <p
            v-if="schoolClass.profile_name"
            class="text-sm text-muted-foreground"
        >
            Avaliada por:
            <span class="font-medium text-foreground">{{
                schoolClass.profile_name
            }}</span>
        </p>
        <div
            v-else
            class="space-y-2 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            <p>Esta turma ainda não tem perfil de avaliação associado.</p>
            <form
                v-if="availableProfiles.length"
                class="flex flex-wrap items-center gap-2"
                @submit.prevent="assignProfile"
            >
                <select
                    v-model.number="profileForm.assessment_profile_version_id"
                    class="h-9 rounded-md border border-amber-300 bg-white px-3 text-sm text-foreground"
                >
                    <option :value="null" disabled>Escolher perfil…</option>
                    <option
                        v-for="profile in availableProfiles"
                        :key="profile.version_id"
                        :value="profile.version_id"
                    >
                        {{ profile.label }}
                    </option>
                </select>
                <Button
                    type="submit"
                    size="sm"
                    :disabled="profileForm.processing"
                    >Associar</Button
                >
            </form>
            <p v-else class="text-xs">
                Não há perfis ativos para {{ schoolClass.subject }}. Crie e
                ative um perfil primeiro.
            </p>
            <InputError
                :message="profileForm.errors.assessment_profile_version_id"
            />
        </div>

        <div v-if="recurringLessonSlots != null" id="horario">
            <LessonScheduleEditor
                :class-id="schoolClass.id"
                :slots="recurringLessonSlots"
            />
        </div>

        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold">Adicionar aluno</h2>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="students.length"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="openPhotoDialog"
                    >
                        <FileUp class="size-4" /> Adicionar fotos
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="openImportDialog"
                    >
                        <FileUp class="size-4" /> Importar lista
                    </Button>
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                Ficheiros exportados do Intuitivo (ou compatível): modelo
                <strong>EB058e</strong> para a lista de alunos (Excel) e modelo
                <strong>EB019</strong> para as fotos (Word).
            </p>
            <form
                class="grid items-end gap-3 rounded-lg border border-border p-4 sm:grid-cols-[1fr_6rem_auto_auto]"
                @submit.prevent="enroll"
            >
                <div class="grid gap-1.5">
                    <Label for="name" class="text-xs">Nome</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        placeholder="Nome do aluno"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="class_number" class="text-xs">Nº</Label>
                    <Input
                        id="class_number"
                        v-model.number="form.class_number"
                        type="number"
                        min="1"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="enrolled_on" class="text-xs">Entrada</Label>
                    <Input
                        id="enrolled_on"
                        v-model="form.enrolled_on"
                        type="date"
                    />
                </div>
                <Button type="submit" :disabled="form.processing"
                    ><UserPlus class="size-4" /> Inscrever</Button
                >
                <InputError :message="enrollmentLimitError" class="sm:col-span-4" />
            </form>
            <p class="text-xs text-muted-foreground">
                O nome fica guardado de forma cifrada e separada. Só o código
                pseudónimo é usado no processamento por IA.
            </p>
        </section>

        <!--
          Dados administrativos: the school's own identifiers, kept out of the
          way. Optional everywhere in Lapispro — a class typed in by hand works
          without them — and needed the day somebody exports to INOVAR.
        -->
        <details v-if="students.length" class="rounded-lg border border-border">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                Dados administrativos
                <span class="ml-1 font-normal text-muted-foreground">
                    · N.º de processo<template v-if="missingProcessNumbers > 0">
                        — {{ missingProcessNumbers }} por preencher</template
                    >
                </span>
            </summary>

            <div class="space-y-3 border-t border-border p-4">
                <p class="text-sm text-muted-foreground">
                    O N.º de processo é o identificador do aluno na escola.
                    Chega preenchido quando a turma é importada de uma Relação
                    de Turma (EB058e); de outro modo, pode escrevê-lo aqui. É
                    opcional — só a exportação para o INOVAR precisa dele.
                </p>

                <table class="w-full text-sm">
                    <thead class="text-left text-muted-foreground">
                        <tr>
                            <th class="py-1.5 font-medium">Nº</th>
                            <th class="py-1.5 font-medium">Aluno</th>
                            <th class="py-1.5 font-medium">N.º de processo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="student in students"
                            :key="`processo-${student.ulid}`"
                        >
                            <td
                                class="py-1.5 text-muted-foreground tabular-nums"
                            >
                                {{ student.class_number ?? '—' }}
                            </td>
                            <td class="py-1.5">{{ student.name }}</td>
                            <td class="py-1.5">
                                <!-- Text, never a number input: a leading zero is
                                     part of an identifier, and some schools use
                                     letters. -->
                                <input
                                    v-model="processNumbers[student.ulid]"
                                    type="text"
                                    maxlength="64"
                                    inputmode="text"
                                    class="w-40 rounded-md border border-border bg-background px-2 py-1 tabular-nums"
                                    placeholder="—"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="flex items-center justify-end gap-3">
                    <p
                        v-if="processNumberForm.recentlySuccessful"
                        class="text-sm text-emerald-600"
                    >
                        Guardado.
                    </p>
                    <button
                        type="button"
                        class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                        :disabled="processNumberForm.processing"
                        @click="saveProcessNumbers"
                    >
                        Guardar N.º de processo
                    </button>
                </div>
            </div>
        </details>

        <section
            v-if="students.length"
            class="overflow-hidden rounded-lg border border-border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Nº</th>
                        <th class="px-4 py-2.5 font-medium">Nome</th>
                        <th class="px-4 py-2.5 font-medium">Pseudónimo</th>
                        <th class="px-4 py-2.5 font-medium">Entrada</th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Ações
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="student in students" :key="student.ulid">
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ student.class_number ?? '—' }}
                        </td>
                        <td class="px-4 py-3 font-medium">
                            <div class="flex items-center gap-2">
                                <StudentAvatar :photo-url="student.photo_url" />
                                <span>{{ student.name }}</span>
                                <Badge
                                    v-if="student.is_late_entry"
                                    variant="outline"
                                    >ingresso tardio</Badge
                                >
                            </div>
                        </td>
                        <td
                            class="px-4 py-3 font-mono text-xs text-muted-foreground"
                        >
                            {{ student.pseudonym }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ student.enrolled_on }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    v-if="canFollowUp"
                                    as-child
                                    variant="ghost"
                                    size="icon"
                                >
                                    <Link
                                        :href="`/classes/${schoolClass.ulid}/evolucao/${student.ulid}`"
                                        :aria-label="`Acompanhamento de ${student.name}`"
                                        title="Acompanhamento"
                                    >
                                        <Footprints class="size-4" />
                                    </Link>
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Editar dados de ${student.name}`"
                                    title="Editar"
                                    @click="openEdit(student)"
                                >
                                    <Pencil class="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Remover ${student.name} da turma`"
                                    title="Remover"
                                    @click="remove(student)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- NOT DELETED, JUST NOT HERE ANY MORE. Folded away, because a
             teacher works with the class as it stands — but visible, so
             importing a roll that moves three students somewhere else does not
             read as three students having vanished. -->
        <details
            v-if="former_students.length"
            class="rounded-lg border border-dashed border-border"
        >
            <summary
                class="cursor-pointer px-4 py-3 text-sm font-medium select-none"
            >
                Alunos que já não integram a turma
                <span class="ml-1 text-muted-foreground"
                    >· {{ former_students.length }}</span
                >
            </summary>

            <div class="border-t border-border px-4 py-3">
                <p class="mb-3 text-xs text-muted-foreground">
                    Continuam no histórico da turma: os resultados, as
                    classificações e as avaliações intercalares dos períodos em
                    que estiveram inscritos mantêm-se inalterados.
                </p>

                <ul class="divide-y divide-border/60">
                    <li
                        v-for="student in former_students"
                        :key="student.ulid"
                        class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2 text-sm"
                    >
                        <span
                            class="w-6 shrink-0 text-xs text-muted-foreground tabular-nums"
                            >{{ student.class_number ?? '—' }}</span
                        >
                        <span class="min-w-0">{{ student.name }}</span>
                        <span
                            class="ml-auto shrink-0 rounded-full bg-muted px-2.5 py-0.5 text-xs text-muted-foreground"
                            >{{ student.state_label }}</span
                        >
                    </li>
                </ul>
            </div>
        </details>

        <Dialog v-model:open="editDialogOpen">
            <DialogContent>
                <form @submit.prevent="submitEdit">
                    <DialogHeader>
                        <DialogTitle>Editar dados do aluno</DialogTitle>
                        <DialogDescription>
                            Corrige o nome, o número ou a data de entrada. O
                            pseudónimo
                            <span class="font-mono">{{
                                editingStudent?.pseudonym
                            }}</span>
                            não muda, e todos os registos, avaliações e
                            intervenções continuam associados a este aluno.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4 py-4">
                        <div class="grid gap-2">
                            <Label>Fotografia</Label>
                            <div class="flex items-center gap-4">
                                <img
                                    v-if="editingStudent?.photo_url"
                                    :src="editingStudent.photo_url"
                                    alt=""
                                    class="size-20 rounded-md border border-border object-cover"
                                />
                                <div
                                    v-else
                                    class="flex size-20 items-center justify-center rounded-md border border-dashed border-border text-center text-xs text-muted-foreground"
                                >
                                    Sem fotografia
                                </div>
                                <div class="grid gap-2">
                                    <input
                                        ref="photoInput"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        class="text-sm file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-1.5 file:text-sm file:font-medium"
                                        :disabled="
                                            !editingStudent?.has_identity ||
                                            studentPhotoForm.processing
                                        "
                                        @change="onStudentPhotoChange"
                                    />
                                    <Button
                                        v-if="editingStudent?.photo_url"
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        class="justify-self-start text-destructive"
                                        @click="removeStudentPhoto"
                                    >
                                        Remover fotografia
                                    </Button>
                                </div>
                            </div>
                            <p
                                v-if="!editingStudent?.has_identity"
                                class="text-xs text-muted-foreground"
                            >
                                Guarda primeiro o nome do aluno para poderes
                                associar uma fotografia.
                            </p>
                            <p v-else class="text-xs text-muted-foreground">
                                JPG, PNG ou WEBP, até 5 MB. A fotografia é
                                opcional e guardada em armazenamento privado.
                            </p>
                            <InputError
                                :message="studentPhotoForm.errors.photo"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="edit-student-name">Nome</Label>
                            <Input
                                id="edit-student-name"
                                v-model="editForm.name"
                                autocomplete="off"
                                required
                            />
                            <InputError :message="editForm.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="edit-student-number">N.º</Label>
                            <Input
                                id="edit-student-number"
                                v-model="editForm.class_number"
                                type="number"
                                min="1"
                                max="65535"
                            />
                            <InputError
                                :message="editForm.errors.class_number"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="edit-student-enrolled-on"
                                >Data de entrada</Label
                            >
                            <Input
                                id="edit-student-enrolled-on"
                                v-model="editForm.enrolled_on"
                                type="date"
                                required
                            />
                            <InputError
                                :message="editForm.errors.enrolled_on"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="editDialogOpen = false"
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" :disabled="editForm.processing">
                            Guardar alterações
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="importDialogOpen">
            <DialogContent>
                <form @submit.prevent="submitImport">
                    <DialogHeader>
                        <DialogTitle>Importar lista de turma</DialogTitle>
                        <DialogDescription
                            >Ficheiro Excel exportado do Intuitivo — modelo
                            EB058e. Depois de reveres a lista, podes associar
                            fotos num passo separado.</DialogDescription
                        >
                    </DialogHeader>
                    <div class="grid gap-4 py-4">
                        <div class="grid gap-2">
                            <Label for="roster-file">Ficheiro Excel</Label>
                            <FileInput
                                id="roster-file"
                                accept=".xls,.xlsx"
                                @change="onRosterFileChange"
                            />
                            <InputError :message="importForm.errors.roster" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="importForm.processing"
                            >Continuar</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="photoDialogOpen">
            <DialogContent>
                <form @submit.prevent="submitPhotos">
                    <DialogHeader>
                        <DialogTitle>Adicionar fotos</DialogTitle>
                        <DialogDescription>
                            Ficheiro Word exportado do Intuitivo — modelo EB019.
                            Associa as fotos aos alunos já inscritos, através do
                            nome.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4 py-4">
                        <div class="grid gap-2">
                            <Label for="class-photos-file"
                                >Ficheiro Word (fotos)</Label
                            >
                            <FileInput
                                id="class-photos-file"
                                accept=".doc,.docx"
                                @change="onPhotosFileChange"
                            />
                            <InputError :message="photoForm.errors.photos" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="photoForm.processing">
                            Associar fotos
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
