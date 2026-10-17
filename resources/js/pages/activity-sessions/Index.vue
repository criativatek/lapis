<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Download, Pencil } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import ActivitySessionDialog from '@/components/activity-sessions/ActivitySessionDialog.vue';
import ActivitySessionStatePill from '@/components/activity-sessions/ActivitySessionStatePill.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import {
    activitySessionDateLabel,
    activitySessionNumberLabel,
} from '@/lib/activitySessions';
import type { ActivitySessionItem } from '@/lib/activitySessions';
import { capitalizeFirst } from '@/lib/text';

/**
 * O HISTÓRICO DAS «OUTRAS ATIVIDADES» (0.170.0): as sessões com registos do
 * professor — data, horas, atividade, número, estado e o sumário completo —,
 * filtradas por atividade (blocos fechados e sessões sem horário incluídos, que
 * se identificam pela cópia) e por período (por omissão, o ano letivo atual).
 *
 * Só lista o que o professor escreveu ou marcou: uma ocorrência por registar
 * não é histórico. Abrir uma sessão consulta-a e edita-a — o mesmo diálogo do
 * Horário e de Aulas e Sumários. O ficheiro Excel é do filtro atual.
 */
type Pagination = {
    current_page: number;
    last_page: number;
    total: number;
    previous_url: string | null;
    next_url: string | null;
};

const props = defineProps<{
    sessions: ActivitySessionItem[];
    filters: {
        activity: string | null;
        from: string | null;
        to: string | null;
    };
    activityOptions: { value: string; label: string }[];
    pagination: Pagination;
    exportUrl: string;
}>();

const draft = reactive({
    activity: props.filters.activity ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

function applyFilters(): void {
    // `from=`/`to=` vazios são pedidos de propósito (todo o período): vão no
    // pedido, e o servidor não os substitui pelo ano letivo.
    router.get(
        '/activity-sessions',
        {
            activity: draft.activity === '' ? undefined : draft.activity,
            from: draft.from,
            to: draft.to,
        },
        { preserveScroll: true },
    );
}

const dialogOpen = ref(false);
const opened = ref<ActivitySessionItem | null>(null);

function openSession(session: ActivitySessionItem): void {
    opened.value = session;
    dialogOpen.value = true;
}

const summaryLine = computed(() =>
    props.pagination.total === 1
        ? '1 sessão com registos'
        : `${props.pagination.total} sessões com registos`,
);
</script>

<template>
    <Head title="Histórico das outras atividades" />

    <main class="mx-auto w-full max-w-4xl space-y-6 p-4 pb-24 sm:p-6">
        <div class="space-y-3">
            <Button as-child variant="ghost" size="sm" class="-ml-2">
                <Link href="/timetable"
                    ><ArrowLeft class="size-4" /> Horário do Professor</Link
                >
            </Button>
            <Heading
                title="Histórico das outras atividades"
                :description="`O que registaste em apoios, clubes, reuniões e direção de turma — ${summaryLine}.`"
            />
        </div>

        <form
            class="grid items-end gap-3 rounded-lg border p-4 sm:grid-cols-[minmax(0,1fr)_auto_auto_auto]"
            data-testid="activity-sessions-filters"
            @submit.prevent="applyFilters"
        >
            <div class="grid gap-1.5">
                <Label for="activity-sessions-activity">Atividade</Label>
                <NativeSelect
                    id="activity-sessions-activity"
                    v-model="draft.activity"
                >
                    <option value="">Todas</option>
                    <option
                        v-for="option in activityOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </NativeSelect>
            </div>
            <div class="grid gap-1.5">
                <Label for="activity-sessions-from">De</Label>
                <Input
                    id="activity-sessions-from"
                    v-model="draft.from"
                    type="date"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="activity-sessions-to">Até</Label>
                <Input
                    id="activity-sessions-to"
                    v-model="draft.to"
                    type="date"
                />
            </div>
            <Button type="submit" class="min-h-10">Filtrar</Button>
        </form>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-muted-foreground">
                Por omissão, o ano letivo atual.
            </p>
            <!-- Um descarregamento é uma navegação do browser, não uma visita do Inertia. -->
            <Button as-child variant="outline" class="min-h-10">
                <a :href="exportUrl" data-testid="activity-sessions-export"
                    ><Download class="size-4" /> Exportar</a
                >
            </Button>
        </div>

        <EmptyState
            v-if="sessions.length === 0"
            title="Ainda sem registos neste período"
            description="Quando guardares o sumário de uma sessão (ou a marcares como realizada ou não realizada), ela aparece aqui."
        />

        <ol v-else class="grid gap-3" data-testid="activity-sessions-list">
            <li
                v-for="session in sessions"
                :key="session.key"
                class="grid gap-2 rounded-[10px] border border-dashed border-muted-foreground/40 bg-muted/20 p-3"
                data-testid="activity-session-row"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="grid min-w-0 gap-0.5">
                        <span class="text-sm font-medium wrap-anywhere">{{
                            session.title
                        }}</span>
                        <span class="text-xs text-muted-foreground">
                            {{
                                capitalizeFirst(
                                    activitySessionDateLabel(session.occurs_on),
                                )
                            }}
                            · {{ session.start_time }}–{{ session.end_time }} ·
                            {{ session.kind_label
                            }}<template v-if="session.school_class">
                                · {{ session.school_class.label }}</template
                            ><template
                                v-if="activitySessionNumberLabel(session)"
                            >
                                ·
                                {{
                                    activitySessionNumberLabel(session)
                                }}</template
                            >
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <ActivitySessionStatePill
                            :presentation="session.presentation"
                            :label="session.presentation_label"
                            size="sm"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="min-h-10 sm:min-h-9"
                            data-testid="activity-session-open"
                            @click="openSession(session)"
                            ><Pencil class="size-4" aria-hidden="true" />
                            Abrir</Button
                        >
                    </div>
                </div>
                <p
                    v-if="session.has_summary"
                    class="max-w-[72ch] text-sm leading-relaxed wrap-anywhere whitespace-pre-line"
                    data-testid="activity-session-summary-text"
                >
                    {{ session.summary }}
                </p>
            </li>
        </ol>

        <nav
            v-if="pagination.last_page > 1"
            class="flex items-center justify-between gap-2 text-sm"
            aria-label="Páginas do histórico"
        >
            <Button
                v-if="pagination.previous_url"
                as-child
                variant="outline"
                size="sm"
            >
                <Link :href="pagination.previous_url">Anterior</Link>
            </Button>
            <span v-else />
            <span class="text-muted-foreground"
                >Página {{ pagination.current_page }} de
                {{ pagination.last_page }}</span
            >
            <Button
                v-if="pagination.next_url"
                as-child
                variant="outline"
                size="sm"
            >
                <Link :href="pagination.next_url">Seguinte</Link>
            </Button>
            <span v-else />
        </nav>

        <ActivitySessionDialog v-model:open="dialogOpen" :session="opened" />
    </main>
</template>
