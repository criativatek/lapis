<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { statusToneClasses } from '@/lib/statusTone';

type Row = {
    enrollment_ulid: string;
    name: string;
    class_number: number | null;
    status: string | null;
    status_label: string | null;
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string };
    periods: { ulid: string; label: string; selected: boolean }[];
    rows: Row[];
}>();

const selectedPeriod = () => props.periods.find((period) => period.selected) ?? null;

// The entitlement decides whether the entry point exists at all. Hiding it is
// presentation only — the route itself is gated by `module:self_assessment_links`
// on the server, so this is convenience rather than access control (same
// pattern as assessments/Index.vue's canImportGrids).
const canSeeLinks = computed(() => usePage().props.modules.includes('self_assessment_links'));

function selectPeriod(ulid: string): void {
    router.get(`/classes/${props.schoolClass.ulid}/self-assessments/${ulid}`, {}, { preserveScroll: true });
}

function open(row: Row): void {
    const period = selectedPeriod();

    if (period === null) {
        return;
    }

    router.get(`/classes/${props.schoolClass.ulid}/self-assessments/${period.ulid}/${row.enrollment_ulid}`);
}
</script>

<template>
    <Head :title="`Autoavaliações — ${schoolClass.label}`" />

    <div class="mx-auto w-full max-w-2xl space-y-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <Heading :title="`Autoavaliações — ${schoolClass.label}`" :description="schoolClass.subject" />
                <Link href="/self-assessments" class="text-sm text-muted-foreground hover:underline">← Todas as turmas</Link>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div v-if="periods.length" class="flex gap-1">
                    <button
                        v-for="period in periods"
                        :key="period.ulid"
                        type="button"
                        class="rounded-md border px-3 py-1.5 text-sm"
                        :class="period.selected ? 'border-primary bg-primary text-primary-foreground' : 'border-border hover:bg-muted/40'"
                        @click="selectPeriod(period.ulid)"
                    >
                        {{ period.label }}
                    </button>
                </div>
                <Link
                    v-if="canSeeLinks && selectedPeriod()"
                    :href="`/classes/${schoolClass.ulid}/self-assessments/${selectedPeriod()?.ulid}/links`"
                    class="rounded-md border border-border px-3 py-1.5 text-sm text-muted-foreground hover:bg-muted/40"
                >
                    Ligações para os alunos
                </Link>
            </div>
        </div>

        <ul class="divide-y divide-border overflow-hidden rounded-lg border border-border">
            <li v-for="row in rows" :key="row.enrollment_ulid">
                <button type="button" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-muted/30" @click="open(row)">
                    <span>
                        <span class="text-muted-foreground">{{ row.class_number ?? '—' }}</span>
                        <span class="ml-2 font-medium">{{ row.name }}</span>
                    </span>
                    <span class="flex items-center gap-3">
                        <span
                            v-if="row.status"
                            class="rounded-full px-2 py-0.5 text-xs"
                            :class="statusToneClasses(row.status ?? '')"
                        >{{ row.status_label }}</span>
                        <span v-else class="text-xs text-muted-foreground">Por registar</span>
                        <ChevronRight class="size-4 text-muted-foreground" />
                    </span>
                </button>
            </li>
        </ul>
    </div>
</template>
