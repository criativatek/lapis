<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InstrumentForm from './InstrumentForm.vue';

type Option = { id: number; label: string; default_purpose?: string };

type ItemRow = {
    ulid: string;
    // Which section the question sits in, by position in `groups`.
    group_index: number;
    code: string;
    label: string;
    points_possible: number;
    is_bonus: boolean;
    has_scores: boolean;
    domains: { domain_id: number; allocation_percent: number }[];
};

// A group's identity is its ulid, and it has to survive the round trip. Leaving
// it out of `initial` made the form send a group with no ulid, which the server
// then read as a brand-new one and tried to insert at sequence 1 — colliding
// with the group already there.
type GroupRow = { ulid: string; label: string | null };

const props = defineProps<{
    instrument: {
        ulid: string;
        title: string;
        academic_period_id: number;
        instrument_type_id: number;
        applied_on: string;
        status: string;
        purpose: string;
        counts_toward_classification: boolean;
        total_points: number | null;
        allow_bonus: boolean;
        groups: GroupRow[];
        items: ItemRow[];
    };
    schoolClass: { ulid: string; label: string };
    periods: Option[];
    types: Option[];
    domains: Option[];
}>();

const initial = {
    title: props.instrument.title,
    academic_period_id: props.instrument.academic_period_id,
    instrument_type_id: props.instrument.instrument_type_id,
    custom_instrument_type_name: '',
    applied_on: props.instrument.applied_on,
    status: props.instrument.status,
    purpose: props.instrument.purpose,
    counts_toward_classification: props.instrument.counts_toward_classification,
    total_points: props.instrument.total_points ?? '',
    allow_bonus: props.instrument.allow_bonus,
    groups: props.instrument.groups,
    items: props.instrument.items,
};
</script>

<template>
    <Head :title="`Editar ${instrument.title}`" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            :title="`Editar ${instrument.title}`"
            :description="`${schoolClass.label} — questões, cotações e domínios.`"
        />
        <InstrumentForm
            :periods="periods"
            :types="types"
            :domains="domains"
            :initial="initial"
            :submit-url="`/instruments/${instrument.ulid}`"
            method="put"
        />
    </div>
</template>
