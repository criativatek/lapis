<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, ClipboardList, FileUp, Footprints, Pencil, Trash2, UserPlus } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import ClassGroupsSection from '@/components/classes/ClassGroupsSection.vue';
import type { ClassGroup } from '@/components/classes/ClassGroupsSection.vue';
import ExistingStudentPicker from '@/components/classes/ExistingStudentPicker.vue';
import FileInput from '@/components/FileInput.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import LessonScheduleEditor from '@/components/lessons/LessonScheduleEditor.vue';
import type { RecurringLessonSlot } from '@/components/lessons/LessonScheduleEditor.vue';
import StudentAvatar from '@/components/StudentAvatar.vue';
import TableShell from '@/components/TableShell.vue';
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
import { statusToneClasses } from '@/lib/statusTone';
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
    /**
     * Só numa turma de apoio: a turma de origem e o n.º do aluno lá — lidos da
     * inscrição de origem, nunca copiados. Pode haver mais do que uma.
     */
    origins: { label: string; class_number: number | null }[];
    /**
     * Falso quando já existe história pedagógica presa a esta inscrição —
     * avaliações, classificações, registos, medidas. Serve para explicar o
     * botão, nunca para autorizar o que quer que seja: quem recusa é
     * EnrollmentController::destroy(), que volta a perguntar ao servidor.
     */
    can_be_removed: boolean;
    /** A chave que a secção «Grupos» devolve ao servidor. */
    id: number;
    /**
     * O grupo a que pertence agora, ou aquele em que entra quando a sua
     * pertença começar. `null` é «Sem grupo», e é um estado legítimo.
     */
    class_group_id: number | null;
    /** Desde quando essa pertença vale (§ ClassRoster::compositionFor()). */
    class_group_since: string | null;
    /**
     * A janela de não-frequência em vigor hoje — `null` quando frequenta
     * normalmente, que é o estado mais comum (§ ClassCohort).
     */
    subject_participation: {
        reason: string;
        reason_label: string;
        reason_detail: string | null;
        note: string | null;
        effective_from: string;
    } | null;
    /** O resultado externo mais recente desta inscrição, se houver algum. */
    external_result: {
        ulid: string;
        origin: string;
        level_code: string | null;
        numeric_value: string | null;
        recorded_on: string;
    } | null;
};

type ProfileOption = { version_id: number; label: string };

const props = defineProps<{
    schoolClass: {
        id: number;
        ulid: string;
        label: string;
        is_support_class: boolean;
        subject: string;
        academic_year: string;
        grade_level: string | null;
        status: string;
        status_label: string;
        profile_name: string | null;
        archived: boolean;
        archived_at: string | null;
        eligible_for_deletion_at: string | null;
        is_eligible_for_deletion: boolean;
        /** Em preparação e sem história: pode ser eliminada já. */
        can_delete_in_preparation: boolean;
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
    /**
     * `null` — e não uma lista vazia — quando o módulo das aulas não está no
     * plano. É o mesmo sinal que `recurringLessonSlots` dá, e é o que faz a
     * secção inteira não existir em vez de aparecer vazia a convidar a um
     * clique que o servidor recusaria.
     */
    classGroups: ClassGroup[] | null;
    /**
     * «Hoje» limitado ao ano letivo desta turma — a data por omissão dos
     * diálogos «Mover» e «Permutar». Nula quando não há módulo de aulas, pelo
     * mesmo sinal que `classGroups`.
     */
    classGroupsDefaultDate: string | null;
    /** Os motivos do domínio (SubjectParticipationReason) — nunca escritos à mão aqui. */
    subjectParticipationReasons: { value: string; label: string }[];
    /**
     * Falso para um observer: pode ver esta secção, mas os botões que abrem
     * ou fecham a frequência da disciplina ficam escondidos (§
     * SubjectParticipationPolicy::manage()).
     */
    canManageSubjectParticipation: boolean;
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

function archiveClass(): void {
    if (
        !confirm(
            'Arquivar esta turma?\n\nDeixará de aparecer nas turmas ativas, mas todos os dados serão preservados e poderá restaurá-la mais tarde.',
        )
    ) {
        return;
    }

    router.post(`/classes/${props.schoolClass.ulid}/archive`, {}, { preserveScroll: true });
}

function restoreClass(): void {
    router.delete(`/classes/${props.schoolClass.ulid}/archive`, { preserveScroll: true });
}

const deleteClassDialogOpen = ref(false);
const deletingClass = ref(false);

/** Confirmada no diálogo — o servidor volta a verificar tudo. */
function deleteClassPermanently(): void {
    router.delete(`/classes/${props.schoolClass.ulid}`, {
        onStart: () => {
            deletingClass.value = true;
        },
        onFinish: () => {
            deletingClass.value = false;
            deleteClassDialogOpen.value = false;
        },
    });
}

/** «YYYY-MM-DD» → «DD/MM/YYYY», sem passar por `Date` — uma data sem hora não
 * tem fuso horário a desviar o dia. */
function formatDate(iso: string): string {
    const [year, month, day] = iso.split('-');

    return `${day}/${month}/${year}`;
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

/**
 * Quem já está a ser removido não volta a ser pedido.
 *
 * O botão ficava ativo enquanto o DELETE ia a caminho, e o segundo clique
 * pedia a remoção de uma inscrição que o primeiro já tinha apagado — que o
 * servidor deixou de responder com um 404, mas que continua a ser um pedido
 * que nunca devia ter partido. É o ULID e não o índice da linha: a pauta
 * volta a ser desenhada quando a resposta chega.
 */
const removing = ref<Set<string>>(new Set());

function remove(student: Student): void {
    if (removing.value.has(student.ulid)) {
        return;
    }

    if (!confirm(`Remover ${student.name} da turma?`)) {
        return;
    }

    removing.value = new Set(removing.value).add(student.ulid);

    router.delete(`/classes/${props.schoolClass.ulid}/students/${student.ulid}`, {
        preserveScroll: true,
        onFinish: () => {
            const pending = new Set(removing.value);
            pending.delete(student.ulid);
            removing.value = pending;
        },
    });
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

/**
 * «Escolher outro ficheiro», visto deste lado.
 *
 * Quem desiste da pré-visualização volta ao passo de onde saiu — o diálogo de
 * carregamento — e não apenas à turma com o botão algures no ecrã. O parâmetro
 * é apagado do URL a seguir, para que uma atualização da página não reabra um
 * diálogo que o professor entretanto fechou.
 *
 * São dois diálogos porque são dois pontos de partida: importar a lista da
 * turma, e corrigir as fotos de uma turma que já existe. Desistir de uma
 * correção de fotos e cair no diálogo do Excel seria mandar o professor
 * recomeçar por um sítio onde nunca esteve.
 */
onMounted(() => {
    const parameters = new URL(window.location.href).searchParams;

    if (parameters.has('fotos')) {
        openPhotoDialog();
    } else if (parameters.has('importar')) {
        openImportDialog();
    } else {
        return;
    }

    window.history.replaceState({}, '', window.location.pathname);
});

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

// ------------------------------------------------------------------
// Frequência da disciplina e resultado externo. Colocados DEPOIS de todos
// os outros formulários — nunca entre eles — para não deslocar o índice que
// `Show.test.ts` já usa para encontrar cada `useForm()` pela sua posição.
// ------------------------------------------------------------------

const today = new Date().toISOString().slice(0, 10);

const participationDialogOpen = ref(false);
const participatingStudent = ref<Student | null>(null);

const participationForm = useForm<{
    enrollment_id: number | null;
    effective_from: string;
    reason: string;
    reason_detail: string;
    note: string;
}>({
    enrollment_id: null,
    effective_from: today,
    reason: props.subjectParticipationReasons[0]?.value ?? '',
    reason_detail: '',
    note: '',
});

/** Abre o diálogo «Não frequenta a disciplina» — de novo, ou para corrigir
 * a janela já em vigor (§ MarkNotAttendingSubject: mesma data == correção). */
function openMarkNotAttending(student: Student): void {
    participatingStudent.value = student;
    participationForm.clearErrors();
    participationForm.enrollment_id = student.id;
    participationForm.effective_from = student.subject_participation?.effective_from ?? today;
    participationForm.reason = student.subject_participation?.reason ?? props.subjectParticipationReasons[0]?.value ?? '';
    participationForm.reason_detail = student.subject_participation?.reason_detail ?? '';
    participationForm.note = student.subject_participation?.note ?? '';
    participationDialogOpen.value = true;
}

function submitMarkNotAttending(): void {
    participationForm.post(`/classes/${props.schoolClass.ulid}/participations`, {
        preserveScroll: true,
        onSuccess: () => {
            participationDialogOpen.value = false;
        },
    });
}

const reactivateDialogOpen = ref(false);
const reactivatingStudent = ref<Student | null>(null);

const reactivateForm = useForm<{ enrollment_id: number | null; effective_from: string }>({
    enrollment_id: null,
    effective_from: today,
});

function openReactivate(student: Student): void {
    reactivatingStudent.value = student;
    reactivateForm.clearErrors();
    reactivateForm.enrollment_id = student.id;
    reactivateForm.effective_from = today;
    reactivateDialogOpen.value = true;
}

function submitReactivate(): void {
    reactivateForm.post(`/classes/${props.schoolClass.ulid}/participations/reactivations`, {
        preserveScroll: true,
        onSuccess: () => {
            reactivateDialogOpen.value = false;
        },
    });
}

const resultDialogOpen = ref(false);
const resultStudent = ref<Student | null>(null);

const resultForm = useForm<{
    enrollment_id: number | null;
    origin: string;
    recorded_on: string;
    level_code: string;
    numeric_value: string;
}>({
    enrollment_id: null,
    origin: '',
    recorded_on: today,
    level_code: '',
    numeric_value: '',
});

/** Abre o diálogo do resultado externo — vazio, ou a corrigir o que já
 * existe (§ RecordExternalSubjectResult: mesmo período == correção). */
function openRecordResult(student: Student): void {
    resultStudent.value = student;
    resultForm.clearErrors();
    resultForm.enrollment_id = student.id;
    resultForm.origin = student.external_result?.origin ?? '';
    resultForm.recorded_on = student.external_result?.recorded_on ?? today;
    resultForm.level_code = student.external_result?.level_code ?? '';
    resultForm.numeric_value = student.external_result?.numeric_value ?? '';
    resultDialogOpen.value = true;
}

function submitRecordResult(): void {
    resultForm.post(`/classes/${props.schoolClass.ulid}/external-results`, {
        preserveScroll: true,
        onSuccess: () => {
            resultDialogOpen.value = false;
        },
    });
}

function deleteResult(student: Student): void {
    if (student.external_result === null) {
        return;
    }

    if (!confirm(`Remover o resultado externo de ${student.name}?`)) {
        return;
    }

    router.delete(`/classes/${props.schoolClass.ulid}/external-results/${student.external_result.ulid}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="schoolClass.label" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="schoolClass.label"
                :description="`${schoolClass.subject} · ${schoolClass.academic_year}`"
            />
            <div class="flex flex-col items-end gap-1.5">
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <template v-if="!schoolClass.archived">
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
                        <!-- Posição secundária, de propósito (§11): a ação
                             visível para uma turma ativa ou em preparação é
                             arquivar, nunca eliminar. -->
                        <Button
                            v-if="schoolClass.status === 'preparation' || schoolClass.status === 'active'"
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="archiveClass"
                        >
                            <Archive class="size-4" /> Arquivar turma
                        </Button>
                        <Button
                            v-if="schoolClass.can_delete_in_preparation"
                            type="button"
                            variant="destructive"
                            size="sm"
                            @click="deleteClassDialogOpen = true"
                        >
                            <Trash2 class="size-4" /> Eliminar definitivamente
                        </Button>
                    </template>
                    <template v-else>
                        <Button type="button" variant="outline" size="sm" @click="restoreClass">
                            <ArchiveRestore class="size-4" /> Restaurar turma
                        </Button>
                        <Button
                            v-if="schoolClass.is_eligible_for_deletion"
                            type="button"
                            variant="destructive"
                            size="sm"
                            @click="deleteClassDialogOpen = true"
                        >
                            <Trash2 class="size-4" /> Eliminar definitivamente
                        </Button>
                    </template>
                    <Badge v-if="schoolClass.is_support_class" variant="outline">
                        Turma de apoio
                    </Badge>
                    <Badge variant="secondary" :class="statusToneClasses(schoolClass.status)">{{
                        schoolClass.status_label
                    }}</Badge>
                </div>
                <p v-if="schoolClass.archived" class="text-xs text-muted-foreground">
                    Arquivada
                    <template v-if="schoolClass.is_eligible_for_deletion">
                        · Elegível para eliminação definitiva
                    </template>
                    <template v-else-if="schoolClass.eligible_for_deletion_at">
                        · Elegível para eliminação definitiva a partir de
                        {{ formatDate(schoolClass.eligible_for_deletion_at) }}
                    </template>
                </p>
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
                :groups="classGroups ?? []"
            />
        </div>

        <!--
            Entre o Horário e os Alunos, encostada aos dois: os grupos saem da
            relação de turma e servem os tempos do horário, e é entre essas
            duas coisas que se lêem. Aparece pelo mesmo sinal que o editor de
            horário — sem o módulo das aulas, não há tempos onde usar um grupo.
        -->
        <ClassGroupsSection
            v-if="classGroups != null"
            id="grupos"
            :class-ulid="schoolClass.ulid"
            :groups="classGroups"
            :students="students"
            :default-date="classGroupsDefaultDate ?? ''"
        />

        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">Adicionar aluno</h2>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="students.length"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="openPhotoDialog"
                    >
                        <FileUp class="size-4" /> Adicionar ou corrigir fotos
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="openImportDialog"
                    >
                        <FileUp class="size-4" /> Importar lista
                    </Button>
                    <!-- Navegação pura, nunca uma submissão: a importação da
                         lista e das fotos já deixam o professor de volta
                         nesta página, sem um sinal explícito de «terminei».
                         Este botão é esse sinal — um gesto de saída, não uma
                         mutação de dados. -->
                    <Button as-child size="sm">
                        <Link :href="`/classes/${schoolClass.ulid}`">
                            Concluir
                        </Link>
                    </Button>
                </div>
            </div>
            <!-- Turma de apoio: primeiro o aluno que já existe; o formulário
                 manual e a importação continuam logo abaixo para quem ainda
                 não está no Lapispro. -->
            <ExistingStudentPicker
                v-if="schoolClass.is_support_class && !schoolClass.archived"
                :class-ulid="schoolClass.ulid"
            />
            <p
                v-if="schoolClass.is_support_class && !schoolClass.archived"
                class="text-xs font-medium text-muted-foreground"
            >
                Aluno ainda não existe no Lapispro? Adicione-o como novo:
            </p>
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
            <TableShell>
                <template #head>
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Nº</th>
                        <th class="px-4 py-2.5 font-medium">Nome</th>
                        <th class="px-4 py-2.5 font-medium">Pseudónimo</th>
                        <th class="px-4 py-2.5 font-medium">Entrada</th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Ações
                        </th>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="student in students" :key="student.ulid">
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ student.class_number ?? '—' }}
                        </td>
                        <td class="px-4 py-3 font-medium">
                            <div class="flex items-center gap-2">
                                <StudentAvatar :photo-url="student.photo_url" :student-name="student.name" zoomable />
                                <div class="min-w-0">
                                    <span>{{ student.name }}</span>
                                    <!-- Turma de apoio: de onde vem o aluno.
                                         Todas as origens, nunca uma escolhida. -->
                                    <p
                                        v-if="schoolClass.is_support_class && student.origins.length"
                                        class="text-xs font-normal text-muted-foreground"
                                    >
                                        <template v-for="(origin, index) in student.origins" :key="`${origin.label}-${index}`">
                                            <template v-if="index > 0"> · </template>{{ origin.label }}<template v-if="origin.class_number != null">, n.º {{ origin.class_number }}</template>
                                        </template>
                                    </p>
                                    <!-- Estado / Motivo / Desde / Resultado externo — a
                                         frequência da disciplina, ao lado do nome. -->
                                    <p
                                        v-if="student.subject_participation"
                                        class="text-xs font-normal text-muted-foreground"
                                    >
                                        Motivo: {{ student.subject_participation.reason_label }}<template v-if="student.subject_participation.reason_detail">, {{ student.subject_participation.reason_detail }}</template>
                                        · Desde {{ formatDate(student.subject_participation.effective_from) }}
                                        <template v-if="canManageSubjectParticipation">
                                            ·
                                            <button type="button" class="underline" @click="openMarkNotAttending(student)">Editar</button>
                                            ·
                                            <button type="button" class="underline" @click="openReactivate(student)">Reativar</button>
                                        </template>
                                    </p>
                                    <p
                                        v-if="student.external_result"
                                        class="text-xs font-normal text-muted-foreground"
                                    >
                                        Resultado externo: {{ student.external_result.level_code ?? student.external_result.numeric_value }}
                                        ({{ student.external_result.origin }}, {{ formatDate(student.external_result.recorded_on) }})
                                        <template v-if="canManageSubjectParticipation">
                                            ·
                                            <button type="button" class="underline" @click="openRecordResult(student)">Editar</button>
                                        </template>
                                    </p>
                                </div>
                                <Badge
                                    v-if="student.is_late_entry"
                                    variant="outline"
                                    >ingresso tardio</Badge
                                >
                                <!-- Discreto de propósito: «não frequenta» não é
                                     uma falta nem um alarme, é um facto como
                                     outro qualquer sobre o aluno. -->
                                <Badge
                                    v-if="student.subject_participation"
                                    variant="secondary"
                                    :title="`Desde ${formatDate(student.subject_participation.effective_from)}`"
                                    >não frequenta {{ schoolClass.subject }}</Badge
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
                                    class="size-11"
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
                                    v-if="canManageSubjectParticipation && !student.subject_participation"
                                    variant="ghost"
                                    size="icon"
                                    class="size-11"
                                    :aria-label="`Marcar ${student.name} como não frequentando ${schoolClass.subject}`"
                                    title="Marcar como não frequentando a disciplina"
                                    @click="openMarkNotAttending(student)"
                                >
                                    <ClipboardList class="size-4" />
                                </Button>
                                <Button
                                    v-if="canManageSubjectParticipation && !student.external_result"
                                    variant="ghost"
                                    size="icon"
                                    class="size-11"
                                    :aria-label="`Registar resultado externo de ${student.name}`"
                                    title="Registar resultado externo"
                                    @click="openRecordResult(student)"
                                >
                                    <FileUp class="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-11"
                                    :aria-label="`Editar dados de ${student.name}`"
                                    title="Editar"
                                    @click="openEdit(student)"
                                >
                                    <Pencil class="size-4" />
                                </Button>
                                <!-- Desativado, e não escondido: um botão que
                                     desaparece deixa o professor a procurá-lo.
                                     O `title` diz porquê, e o `aria-label`
                                     leva a mesma razão a quem não vê o
                                     tooltip. -->
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-11"
                                    :disabled="
                                        !student.can_be_removed ||
                                        removing.has(student.ulid)
                                    "
                                    :aria-label="
                                        student.can_be_removed
                                            ? `Remover ${student.name} da turma`
                                            : `${student.name} não pode ser removido: já tem registos pedagógicos nesta turma`
                                    "
                                    :title="
                                        student.can_be_removed
                                            ? 'Remover'
                                            : 'Já tem registos pedagógicos nesta turma — os dados são preservados'
                                    "
                                    @click="remove(student)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                </template>
            </TableShell>
        </section>

        <div v-if="students.length" class="flex justify-end">
            <Button as-child variant="link" size="sm">
                <Link :href="`/classes/${schoolClass.ulid}/characterisation`">Caracterização pedagógica</Link>
            </Button>
        </div>

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

        <!-- O mesmo «Concluir» do topo, no fim da lista: numa turma de trinta
             alunos o de cima já saiu do ecrã. Navegação pura, mesmo destino. -->
        <div v-if="students.length" class="flex justify-end">
            <Button as-child size="sm" class="min-h-11 w-full sm:w-auto">
                <Link :href="`/classes/${schoolClass.ulid}`">Concluir</Link>
            </Button>
        </div>

        <Dialog v-model:open="deleteClassDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader class="space-y-2">
                    <DialogTitle>Eliminar definitivamente esta turma?</DialogTitle>
                    <DialogDescription>
                        Esta ação não pode ser anulada. Os alunos partilhados
                        noutras turmas não serão eliminados.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" class="min-h-11" @click="deleteClassDialogOpen = false">Cancelar</Button>
                    <Button type="button" variant="destructive" class="min-h-11" :disabled="deletingClass" @click="deleteClassPermanently">
                        Eliminar definitivamente
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

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
                        <DialogTitle>Adicionar ou corrigir fotos</DialogTitle>
                        <DialogDescription>
                            Ficheiro Word exportado do Intuitivo — modelo EB019.
                            Serve para adicionar fotos e para corrigir as que
                            ficaram erradas: a associação é revista antes de ser
                            aplicada, e nenhum aluno é eliminado nem recriado.
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
                            Rever associação
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="participationDialogOpen">
            <DialogContent>
                <form @submit.prevent="submitMarkNotAttending">
                    <DialogHeader>
                        <DialogTitle
                            >{{ participatingStudent?.subject_participation ? 'Corrigir' : 'Marcar como não frequentando' }}
                            {{ schoolClass.subject }}</DialogTitle
                        >
                        <DialogDescription>
                            {{ participatingStudent?.name }} continua inscrito na turma — só deixa de ser
                            avaliado a {{ schoolClass.subject }} a partir da data indicada.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4 py-4">
                        <div class="grid gap-2">
                            <Label for="participation-effective-from">A partir de</Label>
                            <Input
                                id="participation-effective-from"
                                v-model="participationForm.effective_from"
                                type="date"
                            />
                            <InputError :message="participationForm.errors.effective_from" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="participation-reason">Motivo</Label>
                            <select
                                id="participation-reason"
                                v-model="participationForm.reason"
                                class="h-10 w-full rounded-md border border-border bg-background px-3 text-sm"
                            >
                                <option v-for="reason in subjectParticipationReasons" :key="reason.value" :value="reason.value">
                                    {{ reason.label }}
                                </option>
                            </select>
                            <InputError :message="participationForm.errors.reason" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="participation-reason-detail">Detalhe (opcional)</Label>
                            <Input
                                id="participation-reason-detail"
                                v-model="participationForm.reason_detail"
                                maxlength="64"
                                placeholder="Ex.: PLNM"
                            />
                            <InputError :message="participationForm.errors.reason_detail" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="participation-note">Nota (opcional)</Label>
                            <Input id="participation-note" v-model="participationForm.note" maxlength="255" />
                            <InputError :message="participationForm.errors.note" />
                        </div>
                    </div>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" class="min-h-11" @click="participationDialogOpen = false">
                            Cancelar
                        </Button>
                        <Button type="submit" class="min-h-11" :disabled="participationForm.processing">
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="reactivateDialogOpen">
            <DialogContent>
                <form @submit.prevent="submitReactivate">
                    <DialogHeader>
                        <DialogTitle>Voltar a frequentar {{ schoolClass.subject }}</DialogTitle>
                        <DialogDescription>
                            {{ reactivatingStudent?.name }} volta aos fluxos normais de avaliação de
                            {{ schoolClass.subject }} a partir da data indicada.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4 py-4">
                        <div class="grid gap-2">
                            <Label for="reactivate-effective-from">A partir de</Label>
                            <Input
                                id="reactivate-effective-from"
                                v-model="reactivateForm.effective_from"
                                type="date"
                            />
                            <InputError :message="reactivateForm.errors.effective_from" />
                        </div>
                    </div>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" class="min-h-11" @click="reactivateDialogOpen = false">
                            Cancelar
                        </Button>
                        <Button type="submit" class="min-h-11" :disabled="reactivateForm.processing">
                            Reativar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="resultDialogOpen">
            <DialogContent>
                <form @submit.prevent="submitRecordResult">
                    <DialogHeader>
                        <DialogTitle>Resultado externo — {{ schoolClass.subject }}</DialogTitle>
                        <DialogDescription>
                            {{ resultStudent?.name }}. Uma classificação obtida fora do sistema — indique pelo
                            menos o código de nível ou o valor numérico.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4 py-4">
                        <div class="grid gap-2">
                            <Label for="result-origin">Origem</Label>
                            <Input id="result-origin" v-model="resultForm.origin" maxlength="64" placeholder="Ex.: PLNM" />
                            <InputError :message="resultForm.errors.origin" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="result-recorded-on">Data</Label>
                            <Input id="result-recorded-on" v-model="resultForm.recorded_on" type="date" />
                            <InputError :message="resultForm.errors.recorded_on" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="result-level-code">Código de nível</Label>
                            <Input id="result-level-code" v-model="resultForm.level_code" maxlength="16" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="result-numeric-value">Valor numérico</Label>
                            <Input id="result-numeric-value" v-model="resultForm.numeric_value" type="number" step="0.001" />
                            <!-- `scale_level_id` — o campo do lado do servidor
                                 quando nem o código nem o valor chegam — não é
                                 um campo deste formulário; lido por índice de
                                 string, como `enrollmentLimitError` já faz. -->
                            <InputError :message="(resultForm.errors as Record<string, string>).scale_level_id" />
                        </div>
                    </div>
                    <DialogFooter class="gap-2 sm:justify-between">
                        <Button
                            v-if="resultStudent?.external_result"
                            type="button"
                            variant="ghost"
                            class="min-h-11 text-destructive"
                            @click="resultStudent && deleteResult(resultStudent)"
                        >
                            Remover
                        </Button>
                        <div class="flex gap-2">
                            <Button type="button" variant="outline" class="min-h-11" @click="resultDialogOpen = false">
                                Cancelar
                            </Button>
                            <Button type="submit" class="min-h-11" :disabled="resultForm.processing">
                                Guardar
                            </Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
