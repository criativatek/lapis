<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, FileDown, GitBranch, PencilLine, Send, ShieldCheck, SlidersHorizontal } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';

type Event = {
    ulid: string;
    event: string;
    summary: string | null;
    causer: string | null;
    subject_type: string | null;
    at: string;
};

const props = defineProps<{ events: Event[]; scope: 'own' | 'organization' }>();

const description = computed(() =>
    props.scope === 'organization'
        ? 'Quem fez o quê, e quando — toda a atividade da organização. O registo é imutável.'
        : 'Quem fez o quê, e quando — a sua atividade. O registo é imutável.',
);

const meta: Record<string, { label: string; icon: unknown }> = {
    'classification.confirmed': { label: 'Classificação confirmada', icon: CheckCircle2 },
    'classification.overridden': { label: 'Classificação alterada', icon: PencilLine },
    'classification.published': { label: 'Classificações publicadas', icon: Send },
    'class.profile_migrated': { label: 'Turma migrada', icon: GitBranch },
    'profile_version.activated': { label: 'Versão de perfil ativada', icon: SlidersHorizontal },
    'report.exported': { label: 'Pauta exportada', icon: FileDown },
};

function label(event: string): string {
    return meta[event]?.label ?? event;
}

function icon(event: string): unknown {
    return meta[event]?.icon ?? ShieldCheck;
}

function when(iso: string): string {
    return new Date(iso).toLocaleString('pt-PT', { dateStyle: 'medium', timeStyle: 'short' });
}
</script>

<template>
    <Head title="Registo de atividade" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading title="Registo de atividade" :description="description" />

        <div v-if="events.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <ShieldCheck class="mx-auto mb-3 size-8 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">Ainda não há eventos registados.</p>
        </div>

        <ul v-else class="space-y-2">
            <li v-for="event in events" :key="event.ulid" class="flex items-start gap-3 rounded-lg border border-border p-3">
                <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-accent text-accent-foreground">
                    <component :is="icon(event.event)" class="size-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                        <span class="font-medium">{{ label(event.event) }}</span>
                        <span class="text-xs text-muted-foreground tabular-nums">{{ when(event.at) }}</span>
                    </div>
                    <p v-if="event.summary" class="text-sm text-muted-foreground">{{ event.summary }}</p>
                    <p v-if="event.causer" class="text-xs text-muted-foreground">por {{ event.causer }}</p>
                </div>
            </li>
        </ul>
    </div>
</template>
