<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import Form from './Form.vue';

type Option = { value: string; label: string };

defineProps<{
    statuses: Option[];
    periodKinds: Option[];
    exceptionTypes: Option[];
}>();
</script>

<template>
    <Head title="Novo ano letivo" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            title="Novo ano letivo"
            description="Defina o ano, os seus períodos de avaliação e os dias em que não há aula."
        />
        <Form
            :statuses="statuses"
            :period-kinds="periodKinds"
            :exception-types="exceptionTypes"
            submit-url="/academic-years"
            method="post"
        />
    </div>
</template>
