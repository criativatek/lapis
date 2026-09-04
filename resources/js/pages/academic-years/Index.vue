<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarPlus, Pencil, Trash2 } from '@lucide/vue';
import AcademicStructureTabs from '@/components/AcademicStructureTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import TableShell from '@/components/TableShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { statusToneClasses } from '@/lib/statusTone';

type AcademicYear = {
    ulid: string;
    label: string;
    starts_on: string;
    ends_on: string;
    status: string;
    status_label: string;
    periods_count: number;
    editable: boolean;
};

defineProps<{
    academicYears: AcademicYear[];
    canManage: boolean;
}>();

function destroy(year: AcademicYear): void {
    if (confirm(`Eliminar o ano letivo ${year.label}? Esta ação não pode ser anulada.`)) {
        router.delete(`/academic-years/${year.ulid}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Anos letivos" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <AcademicStructureTabs />

        <div class="flex items-center justify-between">
            <Heading title="Anos letivos" description="O ano letivo é a base de todo o trabalho de avaliação." />
            <Button v-if="canManage" as-child>
                <Link href="/academic-years/create">
                    <CalendarPlus class="size-4" /> Novo ano letivo
                </Link>
            </Button>
        </div>

        <EmptyState
            v-if="academicYears.length === 0"
            title="Ainda não tem anos letivos. Crie o primeiro para começar a configurar turmas e avaliações."
        />

        <TableShell v-else>
            <template #head>
                <tr>
                    <th class="px-4 py-2.5 font-medium">Ano</th>
                    <th class="px-4 py-2.5 font-medium">Período</th>
                    <th class="px-4 py-2.5 font-medium">Estado</th>
                    <th class="px-4 py-2.5 text-right font-medium">Ações</th>
                </tr>
            </template>
            <template #body>
                <tr v-for="year in academicYears" :key="year.ulid">
                        <td class="px-4 py-3 font-medium">{{ year.label }}</td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ year.starts_on }} — {{ year.ends_on }} · {{ year.periods_count }} períodos
                        </td>
                        <td class="px-4 py-3">
                            <Badge variant="secondary" :class="statusToneClasses(year.status)">{{ year.status_label }}</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Button as-child variant="ghost" size="icon" aria-label="Editar">
                                    <Link :href="`/academic-years/${year.ulid}/edit`"><Pencil class="size-4" /></Link>
                                </Button>
                                <Button
                                    v-if="canManage && year.status === 'draft'"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Eliminar"
                                    @click="destroy(year)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </td>
                    </tr>
            </template>
        </TableShell>
    </div>
</template>
