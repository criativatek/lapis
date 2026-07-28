<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ClipboardPlus, FileUp, Trash2, UserPlus } from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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

type Student = {
    ulid: string;
    name: string;
    pseudonym: string;
    class_number: number | null;
    enrolled_on: string;
    is_late_entry: boolean;
    status_label: string;
    photo_url: string | null;
};

type ProfileOption = { version_id: number; label: string };

const props = defineProps<{
    schoolClass: {
        ulid: string;
        label: string;
        subject: string;
        academic_year: string;
        grade_level: string | null;
        status_label: string;
        profile_name: string | null;
    };
    students: Student[];
    availableProfiles: ProfileOption[];
    instruments: {
        ulid: string;
        title: string;
        type: string;
        applied_on: string;
        status_label: string;
    }[];
}>();

const profileForm = useForm<{ assessment_profile_version_id: number | null }>({
    assessment_profile_version_id: null,
});

function assignProfile(): void {
    profileForm.put(`/classes/${props.schoolClass.ulid}/profile`, {
        preserveScroll: true,
    });
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

function remove(student: Student): void {
    if (confirm(`Remover ${student.name} da turma?`)) {
        router.delete(
            `/classes/${props.schoolClass.ulid}/students/${student.ulid}`,
            { preserveScroll: true },
        );
    }
}

const importDialogOpen = ref(false);
const wantsPhotos = ref(false);
const importForm = useForm<{ roster: File | null; photos: File | null }>({
    roster: null,
    photos: null,
});

// Unchecking "Queres associar fotos?" must genuinely drop any previously
// selected file — otherwise a teacher who picks the wrong file, unchecks the
// box, and submits would silently send a file the UI shows as un-selected.
watch(wantsPhotos, (value) => {
    if (!value) {
        importForm.photos = null;
    }
});

function openImportDialog(): void {
    importForm.reset();
    importForm.clearErrors();
    wantsPhotos.value = false;
    importDialogOpen.value = true;
}

function onRosterFileChange(event: Event): void {
    importForm.roster = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function onPhotosFileChange(event: Event): void {
    importForm.photos = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submitImport(): void {
    importForm.post(`/classes/${props.schoolClass.ulid}/roster-imports`, {
        forceFormData: true,
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
            <Badge variant="secondary">{{ schoolClass.status_label }}</Badge>
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

        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold">Adicionar aluno</h2>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="openImportDialog"
                >
                    <FileUp class="size-4" /> Importar lista
                </Button>
            </div>
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
            </form>
            <p class="text-xs text-muted-foreground">
                O nome fica guardado de forma cifrada e separada. Só o código
                pseudónimo é usado no processamento por IA.
            </p>
        </section>

        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold">Instrumentos de avaliação</h2>
                <Button as-child variant="outline" size="sm">
                    <Link
                        :href="`/classes/${schoolClass.ulid}/instruments/create`"
                    >
                        <ClipboardPlus class="size-4" /> Novo instrumento
                    </Link>
                </Button>
            </div>
            <p
                v-if="instruments.length === 0"
                class="rounded-lg border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
            >
                Ainda não há instrumentos nesta turma.
            </p>
            <ul
                v-else
                class="divide-y divide-border overflow-hidden rounded-lg border border-border"
            >
                <li v-for="instrument in instruments" :key="instrument.ulid">
                    <Link
                        :href="`/instruments/${instrument.ulid}`"
                        class="flex items-center justify-between px-4 py-3 hover:bg-muted/30"
                    >
                        <span>
                            <span class="font-medium">{{
                                instrument.title
                            }}</span>
                            <span class="ml-2 text-xs text-muted-foreground"
                                >{{ instrument.type }} ·
                                {{ instrument.applied_on }}</span
                            >
                        </span>
                        <Badge variant="secondary">{{
                            instrument.status_label
                        }}</Badge>
                    </Link>
                </li>
            </ul>
        </section>

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
                                <img
                                    v-if="student.photo_url"
                                    :src="student.photo_url"
                                    :alt="student.name"
                                    class="size-6 rounded-full object-cover"
                                />
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
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Remover"
                                @click="remove(student)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <Dialog v-model:open="importDialogOpen">
            <DialogContent>
                <form @submit.prevent="submitImport">
                    <DialogHeader>
                        <DialogTitle>Importar lista de turma</DialogTitle>
                        <DialogDescription
                            >Ficheiro Excel exportado do
                            Intuitivo.</DialogDescription
                        >
                    </DialogHeader>
                    <div class="grid gap-4 py-4">
                        <div class="grid gap-2">
                            <Label for="roster-file">Ficheiro Excel</Label>
                            <input
                                id="roster-file"
                                type="file"
                                accept=".xls,.xlsx"
                                class="text-sm"
                                @change="onRosterFileChange"
                            />
                            <InputError :message="importForm.errors.roster" />
                        </div>
                        <div class="flex items-center gap-2">
                            <input
                                id="wants-photos"
                                v-model="wantsPhotos"
                                type="checkbox"
                            />
                            <Label for="wants-photos"
                                >Queres associar fotos?</Label
                            >
                        </div>
                        <div v-if="wantsPhotos" class="grid gap-2">
                            <Label for="photos-file"
                                >Ficheiro Word (fotos)</Label
                            >
                            <input
                                id="photos-file"
                                type="file"
                                accept=".doc,.docx"
                                class="text-sm"
                                @change="onPhotosFileChange"
                            />
                            <InputError :message="importForm.errors.photos" />
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
    </div>
</template>
