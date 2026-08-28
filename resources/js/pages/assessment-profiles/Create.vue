<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ContextualHelp from '@/components/ContextualHelp.vue';
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

type HelpArticle = { id: string; title: string; summary: string };

defineProps<{
    academicYears: Option[];
    subjects: Option[];
    scales: Option[];
    helpArticles?: HelpArticle[];
}>();
</script>

<template>
    <Head title="Novo perfil de avaliação" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            title="Novo perfil de avaliação"
            description="Defina domínios, ponderações e a escala. Guardar cria um rascunho."
        />
        <ContextualHelp :articles="helpArticles" />
        <ProfileForm
            :academic-years="academicYears"
            :subjects="subjects"
            :scales="scales"
            submit-url="/assessment-profiles"
            method="post"
        />
    </div>
</template>
