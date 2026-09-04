<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ClipboardList } from '@lucide/vue';
import { ref } from 'vue';
import ContextualHelp from '@/components/ContextualHelp.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import InstrumentForm from './InstrumentForm.vue';

type Option = { id: number; label: string; default_purpose?: string };

type ItemRow = {
    ulid?: string;
    code: string;
    label: string;
    points_possible: number | null;
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

type ClassOption = { ulid: string; label: string };

type HelpArticle = { id: string; title: string; summary: string };

// schoolClass present (the usual case, reached from a specific class): the
// form below renders exactly as it always has. schoolClass absent (reached
// from Elementos de Avaliação's own "+ Novo" button, via instruments.create-
// picker): no class is known yet, so periods/types/domains — meaningless
// without one — are never sent either, and a lightweight picker is shown
// instead. Picking a class there navigates into THIS SAME route/page for
// that specific class — a normal GET, not a second creation path.
const props = defineProps<{
    schoolClass?: { ulid: string; label: string } | null;
    classes?: ClassOption[];
    periods?: Option[];
    types?: Option[];
    domains?: Option[];
    importableInstruments?: ImportableInstrument[];
    defaultAcademicPeriodId?: number | null;
    defaultCreationMode?: 'quick';
    helpArticles?: HelpArticle[];
}>();

const selectedClassUlid = ref<string | null>(props.classes?.[0]?.ulid ?? null);

function continueToClass(): void {
    if (!selectedClassUlid.value) {
        return;
    }

    router.visit(`/classes/${selectedClassUlid.value}/instruments/create`);
}
</script>

<template>
    <Head title="Novo elemento de avaliação" />

    <div v-if="schoolClass" class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            :title="`Novo Elemento de Avaliação — ${schoolClass.label}`"
            description="Grelha de correção — Defina os domínios, questões e cotações deste Elemento de Avaliação."
        />
        <ContextualHelp :articles="helpArticles" />
        <InstrumentForm
            :periods="periods ?? []"
            :types="types ?? []"
            :domains="domains ?? []"
            :importable-instruments="importableInstruments"
            :default-academic-period-id="defaultAcademicPeriodId"
            :default-creation-mode="defaultCreationMode"
            :school-class="schoolClass"
            :submit-url="`/classes/${schoolClass.ulid}/instruments`"
            method="post"
        />
    </div>

    <div v-else class="mx-auto w-full max-w-lg space-y-6 p-4">
        <Heading
            title="Novo Elemento de Avaliação"
            description="Escolha a turma para a qual quer criar o elemento de avaliação."
        />

        <EmptyState
            v-if="!classes || classes.length === 0"
            title="Precisa de ter pelo menos uma turma sua para criar um elemento de avaliação."
            :icon="ClipboardList"
        >
            <template #action>
                <Button as-child>
                    <Link href="/classes/create">Criar turma</Link>
                </Button>
            </template>
        </EmptyState>

        <form v-else class="space-y-4" @submit.prevent="continueToClass">
            <div class="grid gap-2">
                <Label for="picker-class">Turma</Label>
                <select
                    id="picker-class"
                    v-model="selectedClassUlid"
                    class="h-10 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option v-for="option in classes" :key="option.ulid" :value="option.ulid">{{ option.label }}</option>
                </select>
            </div>
            <Button type="submit" :disabled="!selectedClassUlid">Continuar</Button>
        </form>
    </div>
</template>
