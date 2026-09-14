<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { CheckCircle2, FileDown, GitBranch, PencilLine, Send, ShieldCheck, SlidersHorizontal } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { auditEventLabel } from '@/lib/auditEventLabels';

type Event = {
    ulid: string;
    event: string;
    summary: string | null;
    causer: string | null;
    subject_type: string | null;
    at: string;
};

/**
 * `mine` — «Minha atividade» (/activity), todos os planos, só o que o próprio
 * fez. `organization`/`own` — «Auditoria da organização»
 * (/activity/organization, audit_log): o responsável vê tudo, um membro só o
 * seu. O servidor já decidiu o que vem em `events`; isto só escolhe o texto.
 */
const props = defineProps<{ events: Event[]; scope: 'mine' | 'own' | 'organization' }>();

const page = usePage();
const isMine = computed(() => props.scope === 'mine');

// Apresentação apenas: /activity/organization volta a verificar audit_log.
const canOpenOrganizationAudit = computed(
    () => page.props.modules.includes('audit_log') || page.props.readOnlyModules.includes('audit_log'),
);

const title = computed(() => (isMine.value ? 'Minha atividade' : 'Auditoria da organização'));

const description = computed(() => {
    if (isMine.value) {
        return 'As ações que registou nesta organização, das mais recentes para as mais antigas. O registo é imutável.';
    }

    return props.scope === 'organization'
        ? 'Quem fez o quê, e quando — toda a atividade da organização. O registo é imutável.'
        : 'Quem fez o quê, e quando — a sua atividade. O registo é imutável.';
});

const icons: Record<string, unknown> = {
    'classification.confirmed': CheckCircle2,
    'classification.overridden': PencilLine,
    'classification.published': Send,
    'class.profile_migrated': GitBranch,
    'profile_version.activated': SlidersHorizontal,
    'report.exported': FileDown,
};

function icon(event: string): unknown {
    return icons[event] ?? ShieldCheck;
}

function when(iso: string): string {
    return new Date(iso).toLocaleString('pt-PT', { dateStyle: 'medium', timeStyle: 'short' });
}
</script>

<template>
    <Head :title="title" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading :title="title" :description="description" />

        <!-- Quem tem audit_log vê as duas leituras lado a lado; quem não tem
             só tem uma, e não há nada para alternar. -->
        <nav v-if="canOpenOrganizationAudit" aria-label="Registo de atividade" class="flex flex-wrap gap-2 text-sm">
            <Link
                href="/activity"
                :aria-current="isMine ? 'page' : undefined"
                class="rounded-full border border-border px-3 py-1"
                :class="isMine ? 'bg-accent font-medium text-accent-foreground' : 'text-muted-foreground hover:bg-accent/50'"
            >
                Minha atividade
            </Link>
            <Link
                href="/activity/organization"
                :aria-current="!isMine ? 'page' : undefined"
                class="rounded-full border border-border px-3 py-1"
                :class="!isMine ? 'bg-accent font-medium text-accent-foreground' : 'text-muted-foreground hover:bg-accent/50'"
            >
                Auditoria da organização
            </Link>
        </nav>

        <EmptyState
            v-if="events.length === 0"
            :title="isMine ? 'Ainda não há ações suas registadas.' : 'Ainda não há eventos registados.'"
            :icon="ShieldCheck"
        />

        <ul v-else class="space-y-2">
            <li v-for="event in events" :key="event.ulid" class="flex items-start gap-3 rounded-lg border border-border p-3">
                <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-accent text-accent-foreground">
                    <component :is="icon(event.event)" class="size-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                        <span class="font-medium break-words">{{ auditEventLabel(event.event) }}</span>
                        <span class="text-xs text-muted-foreground tabular-nums">{{ when(event.at) }}</span>
                    </div>
                    <p v-if="event.summary" class="text-sm break-words text-muted-foreground">{{ event.summary }}</p>
                    <p v-if="event.causer && !isMine" class="text-xs text-muted-foreground">por {{ event.causer }}</p>
                </div>
            </li>
        </ul>

        <p v-if="events.length >= 100" class="text-xs text-muted-foreground">A mostrar as 100 ações mais recentes.</p>
    </div>
</template>
