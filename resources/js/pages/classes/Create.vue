<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { id: number; label: string };
type ProfileOption = { version_id: number; label: string; subject_id: number };

const props = defineProps<{
    academicYears: Option[];
    subjects: Option[];
    profiles: ProfileOption[];
    canCreateAcademicYear: boolean;
    canCreateSubject: boolean;
}>();

const form = useForm<{
    label: string;
    academic_year_id: number | null;
    subject_id: number | null;
    grade_level: string;
    assessment_profile_version_id: number | null;
    is_support_class: boolean;
}>({
    is_support_class: false,
    label: '',
    academic_year_id: null,
    subject_id: null,
    grade_level: '',
    assessment_profile_version_id: null,
});

// Only profiles for the chosen subject can be assigned.
const availableProfiles = computed(() =>
    props.profiles.filter((profile) => profile.subject_id === form.subject_id),
);

// `limit` (App\Support\Limits\Limits::assertCanIncreaseFor) is never a field
// of this form — read through a string index the same way
// academic-years/Form.vue does for its own dynamic error keys.
const limitError = computed(() => (form.errors as Record<string, string>).limit);

// A class always belongs to a year and a subject. Without either the two
// required selects are empty and there is nothing to choose, so the page says
// what is missing instead of leaving a form that cannot be completed.
const missingAcademicYear = computed(() => props.academicYears.length === 0);
const missingSubject = computed(() => props.subjects.length === 0);
const missingPrerequisite = computed(() => missingAcademicYear.value || missingSubject.value);

function submit(): void {
    form.post('/classes', { preserveScroll: true });
}
</script>

<template>
    <Head title="Nova turma" />

    <div class="mx-auto w-full max-w-2xl space-y-6 p-4">
        <Heading title="Nova turma" description="Uma turma é avaliada por um perfil ativo." />

        <div v-if="missingPrerequisite" class="space-y-3 rounded-lg border border-border bg-muted/40 p-4" role="status" data-testid="missing-prerequisites">
            <p class="text-sm font-medium">Antes de criar a turma, falta preparar o seguinte.</p>
            <p class="text-sm text-muted-foreground">Uma turma pertence sempre a um ano letivo e a uma disciplina.</p>
            <ul class="space-y-3 text-sm">
                <li v-if="missingAcademicYear" class="flex flex-wrap items-center justify-between gap-2">
                    <span>Ainda não existe nenhum ano letivo.</span>
                    <Button v-if="canCreateAcademicYear" as-child variant="outline" size="sm">
                        <Link href="/academic-years/create">Criar ano letivo</Link>
                    </Button>
                    <span v-else class="text-muted-foreground">Peça ao responsável da organização que o crie.</span>
                </li>
                <li v-if="missingSubject" class="flex flex-wrap items-center justify-between gap-2">
                    <span>Ainda não existe nenhuma disciplina.</span>
                    <Button v-if="canCreateSubject" as-child variant="outline" size="sm">
                        <Link href="/subjects">Adicionar disciplina</Link>
                    </Button>
                    <span v-else class="text-muted-foreground">Peça ao responsável da organização que a adicione.</span>
                </li>
            </ul>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="label">Designação</Label>
                <Input id="label" v-model="form.label" placeholder="Ex.: 7.º A" />
                <InputError :message="form.errors.label" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="academic_year_id">Ano letivo</Label>
                    <select id="academic_year_id" v-model.number="form.academic_year_id" class="border-input h-9 rounded-md border bg-transparent px-3 text-sm">
                        <option :value="null" disabled>Escolher…</option>
                        <option v-for="year in academicYears" :key="year.id" :value="year.id">{{ year.label }}</option>
                    </select>
                    <InputError :message="form.errors.academic_year_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="subject_id">Disciplina</Label>
                    <select id="subject_id" v-model.number="form.subject_id" class="border-input h-9 rounded-md border bg-transparent px-3 text-sm">
                        <option :value="null" disabled>Escolher…</option>
                        <option v-for="subject in subjects" :key="subject.id" :value="subject.id">{{ subject.label }}</option>
                    </select>
                    <InputError :message="form.errors.subject_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="grade_level">Ano de escolaridade</Label>
                    <Input id="grade_level" v-model="form.grade_level" maxlength="16" placeholder="Ex.: 7.º" />
                    <p class="text-xs text-muted-foreground">Opcional.</p>
                    <InputError :message="form.errors.grade_level" />
                </div>
                <div class="grid gap-2">
                    <Label for="assessment_profile_version_id">Perfil de avaliação</Label>
                    <select id="assessment_profile_version_id" v-model.number="form.assessment_profile_version_id" class="border-input h-9 rounded-md border bg-transparent px-3 text-sm">
                        <option :value="null">Sem perfil por agora</option>
                        <option v-for="profile in availableProfiles" :key="profile.version_id" :value="profile.version_id">{{ profile.label }}</option>
                    </select>
                    <InputError :message="form.errors.assessment_profile_version_id" />
                    <p v-if="form.subject_id && availableProfiles.length === 0" class="text-xs text-muted-foreground">
                        Não há perfis ativos para esta disciplina. Pode criar a turma e associar depois.
                    </p>
                </div>
            </div>

            <div class="flex items-start gap-3 rounded-lg border border-border p-3">
                <Checkbox
                    id="is_support_class"
                    :model-value="form.is_support_class"
                    class="mt-0.5"
                    @update:model-value="form.is_support_class = $event === true"
                />
                <div class="grid gap-1">
                    <Label for="is_support_class">Turma de apoio</Label>
                    <p class="text-xs text-muted-foreground">
                        Pode reunir alunos de várias turmas. Os alunos que já estão nas suas turmas são adicionados sem serem criados de novo.
                    </p>
                </div>
            </div>

            <InputError :message="limitError" />
            <Button type="submit" :disabled="form.processing || missingPrerequisite">Criar turma</Button>
        </form>
    </div>
</template>
