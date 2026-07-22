<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { BarChart3, CheckCircle2, ClipboardList, GraduationCap, Send, Users } from '@lucide/vue';
import { computed } from 'vue';

type ClassCard = {
    ulid: string;
    label: string;
    subject: string;
    academic_year: string;
    has_profile: boolean;
    pending_confirmation: number;
    pending_publication: number;
};

const props = defineProps<{
    teacherName: string;
    classes: ClassCard[];
    totals: { classes: number; pending_confirmation: number; pending_publication: number };
}>();

// First name only — a warmer greeting than the full registered name.
const firstName = computed(() => props.teacherName.split(' ')[0]);

const summary = computed(() => [
    { label: 'Turmas', value: props.totals.classes, icon: Users },
    { label: 'Por confirmar', value: props.totals.pending_confirmation, icon: ClipboardList },
    { label: 'Por publicar', value: props.totals.pending_publication, icon: Send },
]);
</script>

<template>
    <Head title="Painel do Professor" />

    <div class="space-y-6 p-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Olá, {{ firstName }}</h1>
            <p class="text-sm text-muted-foreground">Aqui está o que precisa da sua atenção.</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div v-for="item in summary" :key="item.label" class="flex items-center gap-4 rounded-xl border border-border p-4">
                <span class="flex size-11 items-center justify-center rounded-lg bg-accent text-accent-foreground">
                    <component :is="item.icon" class="size-5" />
                </span>
                <div>
                    <div class="text-2xl font-semibold tabular-nums">{{ item.value }}</div>
                    <div class="text-sm text-muted-foreground">{{ item.label }}</div>
                </div>
            </div>
        </div>

        <div>
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-semibold">As minhas turmas</h2>
                <Link href="/classes" class="text-sm text-primary hover:underline">Ver todas</Link>
            </div>

            <div v-if="classes.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
                <GraduationCap class="mx-auto size-8 text-muted-foreground" />
                <p class="mt-2 text-sm text-muted-foreground">Ainda não tem turmas.</p>
                <Link href="/classes/create" class="mt-3 inline-block rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90">
                    Criar a primeira turma
                </Link>
            </div>

            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="schoolClass in classes" :key="schoolClass.ulid" class="flex flex-col rounded-xl border border-border p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-semibold">{{ schoolClass.label }}</div>
                            <div class="text-sm text-muted-foreground">{{ schoolClass.subject }} · {{ schoolClass.academic_year }}</div>
                        </div>
                        <span v-if="!schoolClass.has_profile" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800" title="Sem perfil de avaliação">sem perfil</span>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2 text-xs">
                        <span
                            v-if="schoolClass.pending_confirmation > 0"
                            class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-1 text-muted-foreground"
                        >
                            <ClipboardList class="size-3" /> {{ schoolClass.pending_confirmation }} por confirmar
                        </span>
                        <span
                            v-if="schoolClass.pending_publication > 0"
                            class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-1 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
                        >
                            <Send class="size-3" /> {{ schoolClass.pending_publication }} por publicar
                        </span>
                        <span
                            v-if="schoolClass.pending_confirmation === 0 && schoolClass.pending_publication === 0"
                            class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-1 text-muted-foreground"
                        >
                            <CheckCircle2 class="size-3" /> sem pendências
                        </span>
                    </div>

                    <div class="mt-4 flex gap-3 border-t border-border pt-3 text-sm">
                        <Link :href="`/classes/${schoolClass.ulid}/classifications`" class="inline-flex items-center gap-1 text-primary hover:underline">
                            <ClipboardList class="size-3.5" /> Classificações
                        </Link>
                        <Link :href="`/classes/${schoolClass.ulid}/results`" class="inline-flex items-center gap-1 text-muted-foreground hover:underline">
                            <BarChart3 class="size-3.5" /> Resultados
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
