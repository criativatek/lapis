<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { id: number; label: string };
type ProfileOption = { version_id: number; label: string; subject_id: number };

const props = defineProps<{
    academicYears: Option[];
    subjects: Option[];
    profiles: ProfileOption[];
}>();

const form = useForm<{
    label: string;
    academic_year_id: number | null;
    subject_id: number | null;
    grade_level: string;
    assessment_profile_version_id: number | null;
}>({
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

function submit(): void {
    form.post('/classes', { preserveScroll: true });
}
</script>

<template>
    <Head title="Nova turma" />

    <div class="mx-auto w-full max-w-2xl space-y-6 p-4">
        <Heading title="Nova turma" description="Uma turma é avaliada por um perfil ativo." />

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
                    <Input id="grade_level" v-model="form.grade_level" placeholder="Ex.: 7.º" />
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

            <InputError :message="limitError" />
            <Button type="submit" :disabled="form.processing">Criar turma</Button>
        </form>
    </div>
</template>
