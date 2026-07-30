<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InstrumentForm from './InstrumentForm.vue';

type Option = { id: number; label: string; default_purpose?: string };

type ItemRow = {
    ulid?: string;
    code: string;
    label: string;
    points_possible: number;
    is_bonus: boolean;
    has_scores?: boolean;
    domains: { domain_id: number; allocation_percent: number }[];
};

type ImportableInstrument = {
    ulid: string;
    title: string;
    class_label: string;
    applied_on: string;
    total_points: number | null;
    allow_bonus: boolean;
    items: ItemRow[];
};

defineProps<{
    schoolClass: { ulid: string; label: string };
    periods: Option[];
    types: Option[];
    domains: Option[];
    importableInstruments: ImportableInstrument[];
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
            :importable-instruments="importableInstruments"
            :submit-url="`/classes/${schoolClass.ulid}/instruments`"
            method="post"
        />
    </div>
</template>
