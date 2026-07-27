<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import ProfileForm from './ProfileForm.vue';

type Option = {
    id: number;
    label: string;
    system?: boolean;
    kind: string;
    min_value: number;
    max_value: number;
};
type DomainRow = { name: string; weight: number };

const props = defineProps<{
    profile: {
        ulid: string;
        name: string;
        academic_year_id: number;
        subject_id: number;
        grade_level: string | null;
        description: string | null;
        scale_id: number | null;
        editing_active: boolean;
        domains: DomainRow[];
    };
    academicYears: Option[];
    subjects: Option[];
    scales: Option[];
}>();

const initial = {
    name: props.profile.name,
    academic_year_id: props.profile.academic_year_id,
    subject_id: props.profile.subject_id,
    grade_level: props.profile.grade_level ?? '',
    description: props.profile.description,
    scale_id: props.profile.scale_id,
    domains: props.profile.domains.length
        ? props.profile.domains
        : [{ name: '', weight: 0 }],
};
</script>

<template>
    <Head :title="`Editar ${profile.name}`" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            :title="`Editar ${profile.name}`"
            description="Ajuste os domínios e ponderações do rascunho."
        />

        <p
            v-if="profile.editing_active"
            class="rounded-md border border-blue-300 bg-blue-50 px-4 py-3 text-sm text-blue-900"
        >
            Este perfil já está ativo. Guardar as alterações abre uma nova
            versão em rascunho — a versão ativa e os seus resultados mantêm-se
            intactos até ativar a nova.
        </p>

        <ProfileForm
            :academic-years="academicYears"
            :subjects="subjects"
            :scales="scales"
            :initial="initial"
            :submit-url="`/assessment-profiles/${profile.ulid}`"
            method="put"
        />
    </div>
</template>
