<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import Form from './Form.vue';

type Option = { value: string; label: string };

type Period = {
    label: string;
    kind: string;
    sequence: number;
    starts_on: string;
    ends_on: string;
};

const props = defineProps<{
    academicYear: {
        ulid: string;
        label: string;
        starts_on: string;
        ends_on: string;
        status: string;
        country_code: string;
        region_code: string | null;
        editable: boolean;
        periods: Period[];
    };
    statuses: Option[];
    periodKinds: Option[];
}>();

const initial = {
    label: props.academicYear.label,
    starts_on: props.academicYear.starts_on,
    ends_on: props.academicYear.ends_on,
    status: props.academicYear.status,
    country_code: props.academicYear.country_code,
    region_code: props.academicYear.region_code,
    periods: props.academicYear.periods,
};
</script>

<template>
    <Head :title="`Editar ${academicYear.label}`" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading :title="`Editar ${academicYear.label}`" description="Ajuste o ano letivo e os seus períodos." />

        <p
            v-if="!academicYear.editable"
            class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            Este ano letivo está encerrado e não pode ser alterado.
        </p>

        <Form
            v-else
            :statuses="statuses"
            :period-kinds="periodKinds"
            :initial="initial"
            :submit-url="`/academic-years/${academicYear.ulid}`"
            method="put"
        />
    </div>
</template>
