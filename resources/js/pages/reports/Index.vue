<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronRight, FileText, LayoutTemplate, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import NativeSelect from '@/components/ui/NativeSelect.vue';

type Option = { value: string; label: string };

type ReportRow = {
    ulid: string;
    title: string;
    type: string;
    type_label: string;
    status: string;
    status_label: string;
    subject_label: string;
    scope_label: string;
    author: string | null;
    created_at: string;
    updated_at: string;
    finalized_at: string | null;
    based_on: { ulid: string; title: string } | null;
};

type ClassRow = { id: number; ulid: string; label: string; subject: string };

const props = defineProps<{
    reports: ReportRow[];
    total: number;
    filters: { type: string | null; status: string | null; class_id: number | null };
    availableTypes: Option[];
    statuses: Option[];
    classes: ClassRow[];
}>();

const type = ref(props.filters.type ?? '');
const status = ref(props.filters.status ?? '');
const classId = ref(props.filters.class_id === null ? '' : String(props.filters.class_id));

// One reload for any filter change, replacing the entry rather than stacking
// history: a teacher narrowing a list is not navigating.
watch([type, status, classId], () => {
    router.get(
        '/reports',
        {
            type: type.value || undefined,
            status: status.value || undefined,
            class_id: classId.value || undefined,
        },
        { replace: true, preserveState: true, preserveScroll: true },
    );
});

const hasFilters = computed(() => type.value !== '' || status.value !== '' || classId.value !== '');

function clearFilters() {
    type.value = '';
    status.value = '';
    classId.value = '';
}

const dateFormatter = new Intl.DateTimeFormat('pt-PT', { day: '2-digit', month: 'short', year: 'numeric' });

function formatDate(value: string): string {
    return dateFormatter.format(new Date(value));
}
</script>

<template>
    <Head title="Relatórios" />

    <div class="mx-auto w-full max-w-5xl space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Relatórios"
                description="Documentos que descrevem o que os dados dizem — escritos pelo Lapispro, decididos por si."
            />

            <Button v-if="availableTypes.length > 0" as-child>
                <Link href="/reports/novo">
                    <Plus class="size-4" />
                    Novo relatório
                </Link>
            </Button>
        </div>

        <Link
            href="/reports/modelos"
            class="flex items-center justify-between gap-3 rounded-lg border border-border bg-card px-4 py-3 hover:bg-muted/30"
        >
            <span class="flex items-center gap-3">
                <LayoutTemplate class="size-5 shrink-0 text-muted-foreground" />
                <span>
                    <span class="block font-medium">Modelos de relatório</span>
                    <span class="block text-sm text-muted-foreground">
                        A organização de um relatório, guardada para a próxima vez.
                    </span>
                </span>
            </span>
            <ChevronRight class="size-4 shrink-0 text-muted-foreground" />
        </Link>

        <!-- A pauta já NÃO vive aqui. Foi absorvida pela Pauta de Avaliação, no
             menu Avaliação, que mostra a mesma coisa e mais: por domínio, com o
             quantitativo, a apreciação e a distinção entre proposta e decisão.
             Um segundo cartão daqui para lá seria uma segunda porta para o
             mesmo sítio — e a navegação canónica só tem uma. -->

        <div class="flex flex-wrap items-end gap-3">
            <label class="grid gap-1 text-sm">
                <span class="text-xs font-medium text-muted-foreground">Tipo</span>
                <NativeSelect v-model="type">
                    <option value="">Todos</option>
                    <option v-for="option in availableTypes" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </NativeSelect>
            </label>

            <label class="grid gap-1 text-sm">
                <span class="text-xs font-medium text-muted-foreground">Estado</span>
                <NativeSelect v-model="status">
                    <option value="">Todos</option>
                    <option v-for="option in statuses" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </NativeSelect>
            </label>

            <label class="grid gap-1 text-sm">
                <span class="text-xs font-medium text-muted-foreground">Turma</span>
                <NativeSelect v-model="classId">
                    <option value="">Todas</option>
                    <option v-for="schoolClass in classes" :key="schoolClass.id" :value="String(schoolClass.id)">
                        {{ schoolClass.label }} · {{ schoolClass.subject }}
                    </option>
                </NativeSelect>
            </label>

            <Button v-if="hasFilters" variant="ghost" size="sm" @click="clearFilters">Limpar</Button>
        </div>

        <EmptyState
            v-if="reports.length === 0"
            :title="hasFilters ? 'Nenhum relatório corresponde a estes filtros.' : 'Ainda não criou nenhum relatório.'"
            :description="
                !hasFilters
                    ? 'Um relatório parte dos dados que já tem — resultados, classificações atribuídas, registos — e escreve-os em frases que pode rever e editar antes de exportar.'
                    : undefined
            "
            :icon="FileText"
        />

        <ul v-else class="divide-y divide-border overflow-hidden rounded-lg border border-border">
            <li v-for="report in reports" :key="report.ulid">
                <Link
                    :href="`/reports/${report.ulid}`"
                    class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-muted/30"
                >
                    <span class="min-w-0">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ report.title }}</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="
                                    report.status === 'finalized'
                                        ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                        : 'bg-amber-500/10 text-amber-700 dark:text-amber-400'
                                "
                            >
                                {{ report.status_label }}
                            </span>
                        </span>
                        <span class="mt-0.5 block text-sm break-words text-muted-foreground">
                            {{ report.type_label }} · {{ report.subject_label }} · {{ report.scope_label }}
                        </span>
                        <span class="mt-0.5 block text-xs text-muted-foreground">
                            {{ report.author ?? '—' }} · atualizado a {{ formatDate(report.updated_at) }}
                            <template v-if="report.based_on">
                                · a partir de «{{ report.based_on.title }}»
                            </template>
                        </span>
                    </span>
                    <ChevronRight class="size-4 shrink-0 text-muted-foreground" />
                </Link>
            </li>
        </ul>

        <p v-if="total > reports.length" class="text-xs text-muted-foreground">
            A mostrar {{ reports.length }} de {{ total }} relatórios. Use os filtros para encontrar os restantes.
        </p>
    </div>
</template>
