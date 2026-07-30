<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InstrumentForm from './InstrumentForm.vue';

type Option = { id: number; label: string; default_purpose?: string };

defineProps<{
    schoolClass: { ulid: string; label: string };
    periods: Option[];
    types: Option[];
    domains: Option[];
}>();
</script>

<template>
    <Head title="Novo instrumento" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            :title="`Novo instrumento — ${schoolClass.label}`"
            description="Questões, cotações e a que domínios pertencem."
        />
        <InstrumentForm
            :periods="periods"
            :types="types"
            :domains="domains"
            :submit-url="`/classes/${schoolClass.ulid}/instruments`"
            method="post"
        />
    </div>
</template>
