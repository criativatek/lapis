<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { BellRing, ChevronDown, ChevronRight, ClipboardCheck, NotebookPen, Plus, Search, Table2 } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import AbsenceCaseDialog from '@/components/records/AbsenceCaseDialog.vue';
import ClassPanoramaTab from '@/components/records/ClassPanoramaTab.vue';
import FollowupCaseDialog from '@/components/records/FollowupCaseDialog.vue';
import RecordSearchTab from '@/components/records/RecordSearchTab.vue';
import RegisterHomeworkTab from '@/components/records/RegisterHomeworkTab.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import type {
    AbsenceDetail,
    AlertRow,
    CaseDetail,
    ConsultationFilters,
    FollowupRow,
    FollowupView,
    Paginated,
    Panorama,
    RecordsTab,
    RegisterContext,
    ResultRow,
    Tile,
} from '@/lib/recordFollowup';
import { CASE_KEYS, tabQuery, withQuery } from '@/lib/recordFollowup';

/**
 * «Registos» (0.165.0) — para REGISTAR e para CONSULTAR, em três separadores:
 * «Registar TPC» (a grelha da turma, logo à vista), «Panorama da turma» (os
 * quatro indicadores de todos os alunos) e «Pesquisar registos» (a consulta da
 * 0.164.0). Os restantes tipos de registo criam-se em «Novo registo».
 *
 * Só o separador ativo calcula os seus dados no servidor; os diálogos de
 * detalhe (com a mensagem e a marcação «Registado no Inovar») são os mesmos
 * nos três.
 */
type ClassOption = { ulid: string; label: string; subject: string; subject_ulid: string; archived: boolean };
type StudentOption = { ulid: string; number: number | null; name: string };
type KindOption = { value: string; label: string; group_label: string };
type PeriodOption = { ulid: string; label: string; starts_on: string; ends_on: string; is_current: boolean };

const props = defineProps<{
    years: { ulid: string; label: string }[];
    filters: ConsultationFilters;
    options: {
        subjects: { ulid: string; name: string }[];
        classes: ClassOption[];
        students: StudentOption[];
        kinds: KindOption[];
        periods: PeriodOption[];
    };
    context: {
        has_classes: boolean;
        year_label: string | null;
        scope_label: string;
        student_label: string | null;
        window_label: string | null;
        period_is_current: boolean;
        needs_window: boolean;
        window_problem: string | null;
        periods_notice: string | null;
        overlaps: string[];
    };
    register: RegisterContext | null;
    panorama: Panorama | null;
    pendingAlerts: number;
    tiles: Tile[];
    alerts: AlertRow[];
    results: Paginated<ResultRow> | null;
    followup: { category: FollowupView; label: string; unit: 'occurrence' | 'absence'; rows: Paginated<FollowupRow> } | null;
    openCase?: CaseDetail | AbsenceDetail | null;
    can: { confirm: boolean };
}>();

const page = usePage();

const TABS: { key: RecordsTab; label: string; short: string; icon: Component }[] = [
    { key: 'tpc', label: 'Registar TPC', short: 'Registar TPC', icon: ClipboardCheck },
    { key: 'panorama', label: 'Panorama da turma', short: 'Panorama', icon: Table2 },
    { key: 'search', label: 'Pesquisar registos', short: 'Pesquisar', icon: Search },
];

const activeTab = computed<RecordsTab>(() => props.filters.tab ?? 'tpc');

function selectTab(tab: RecordsTab): void {
    if (tab === activeTab.value) {
        return;
    }

    // The search keeps its filters; the grid and the panorama only the year
    // and the class — a student or kind chosen in the search hides nobody.
    router.get('/records', tabQuery(props.filters, tab), { preserveScroll: true });
}

function onTabKeydown(event: KeyboardEvent, index: number): void {
    if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') {
        return;
    }

    event.preventDefault();
    const next = (index + (event.key === 'ArrowRight' ? 1 : -1) + TABS.length) % TABS.length;
    document.getElementById(`records-tab-${TABS[next].key}`)?.focus();
}

const absenceCase = computed<AbsenceDetail | null>(() => (props.openCase && 'kind' in props.openCase && props.openCase.kind === 'absences' ? props.openCase : null));
const recordCase = computed<CaseDetail | null>(() => (props.openCase && !('kind' in props.openCase) ? props.openCase : null));

function openCaseFor(classUlid: string, studentUlid: string, category: FollowupView): void {
    router.get(
        withQuery(page.url, { case_class: classUlid, case_student: studentUlid, case_category: category }),
        {},
        { only: ['openCase'], preserveState: true, preserveScroll: true },
    );
}

function closeCase(): void {
    const changes = Object.fromEntries(CASE_KEYS.map((key) => [key, null]));

    // Closing the detail opened from the panorama refreshes the table too: a
    // marking «Registado no Inovar» changes the mark in its cell.
    router.get(withQuery(page.url, changes), {}, {
        only: activeTab.value === 'panorama' ? ['openCase', 'panorama'] : ['openCase'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

const registerClasses = computed(() => props.options.classes.filter((option) => !option.archived));
const selectedClass = computed(() => registerClasses.value.find((option) => option.ulid === props.filters.class) ?? null);
</script>

<template>
    <Head title="Registos" />

    <div class="mx-auto w-full max-w-5xl space-y-5 p-4">
        <PageHeader title="Registos" description="Regista e consulta informações dos alunos e acompanha os TPC, a assiduidade, os atrasos e as faltas de material.">
            <template v-if="registerClasses.length > 0" #actions>
                <Button v-if="selectedClass" as-child variant="outline" class="min-h-11">
                    <Link :href="`/classes/${selectedClass.ulid}/records`" data-test="new-record"><Plus class="size-4" /> Novo registo</Link>
                </Button>
                <DropdownMenu v-else>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" class="min-h-11" data-test="new-record-menu">
                            <Plus class="size-4" /> Novo registo
                            <ChevronDown class="size-4 opacity-70" aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="min-w-56">
                        <DropdownMenuItem v-for="option in registerClasses" :key="option.ulid" as-child class="min-h-11 cursor-pointer">
                            <Link :href="`/classes/${option.ulid}/records`">{{ option.label }} · {{ option.subject }}</Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </PageHeader>

        <EmptyState v-if="years.length === 0" title="Ainda não tem turmas para registar ou consultar." :icon="NotebookPen">
            <template #action>
                <Button as-child>
                    <Link href="/classes">Ver turmas</Link>
                </Button>
            </template>
        </EmptyState>

        <template v-else>
            <div class="border-b border-border">
                <div class="-mb-px flex gap-1 overflow-x-auto" role="tablist" aria-label="Registos">
                    <button
                        v-for="(tab, index) in TABS"
                        :id="`records-tab-${tab.key}`"
                        :key="tab.key"
                        type="button"
                        role="tab"
                        class="inline-flex min-h-11 shrink-0 items-center gap-1.5 border-b-2 px-2 text-sm font-medium whitespace-nowrap sm:gap-2 sm:px-3 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="activeTab === tab.key ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'"
                        :aria-selected="activeTab === tab.key"
                        :aria-controls="`records-panel-${tab.key}`"
                        :tabindex="activeTab === tab.key ? 0 : -1"
                        :data-test="`tab-${tab.key}`"
                        @click="selectTab(tab.key)"
                        @keydown="onTabKeydown($event, index)"
                    >
                        <component :is="tab.icon" class="size-4 shrink-0" aria-hidden="true" />
                        <span class="sm:hidden">{{ tab.short }}</span><span class="hidden sm:inline">{{ tab.label }}</span>
                    </button>
                </div>
            </div>

            <button
                v-if="pendingAlerts > 0 && activeTab !== 'search'"
                type="button"
                class="flex min-h-11 w-full items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-2 text-left text-sm text-amber-950 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                data-test="pending-alerts"
                @click="selectTab('search')"
            >
                <BellRing class="size-4 shrink-0" aria-hidden="true" />
                <span class="flex-1">
                    {{ pendingAlerts === 1 ? '1 alerta de acumulação por tratar' : `${pendingAlerts} alertas de acumulação por tratar` }} — ver em «Pesquisar registos».
                </span>
                <ChevronRight class="size-4 shrink-0" aria-hidden="true" />
            </button>

            <div :id="`records-panel-${activeTab}`" role="tabpanel" :aria-labelledby="`records-tab-${activeTab}`">
                <RegisterHomeworkTab
                    v-if="activeTab === 'tpc' && register"
                    :register="register"
                    :classes="options.classes"
                    :filters="filters"
                    :year-label="context.year_label"
                />
                <ClassPanoramaTab
                    v-else-if="activeTab === 'panorama'"
                    :panorama="panorama"
                    :filters="filters"
                    :years="years"
                    :options="options"
                    :context="context"
                    @open-case="openCaseFor"
                />
                <RecordSearchTab
                    v-else-if="activeTab === 'search'"
                    :years="years"
                    :filters="filters"
                    :options="options"
                    :context="context"
                    :tiles="tiles"
                    :alerts="alerts"
                    :results="results"
                    :followup="followup"
                    @open-case="openCaseFor"
                />
            </div>
        </template>

        <FollowupCaseDialog v-if="recordCase" :detail="recordCase" :current-url="page.url" @close="closeCase" />
        <AbsenceCaseDialog v-else-if="absenceCase" :detail="absenceCase" :current-url="page.url" @close="closeCase" />
    </div>
</template>
