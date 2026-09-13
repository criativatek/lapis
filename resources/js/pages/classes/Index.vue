<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, ArrowLeft, CalendarPlus, Pencil, Plus, Users } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { statusToneClasses } from '@/lib/statusTone';

export type SchoolClass = {
    ulid: string;
    label: string;
    subject: string;
    academic_year: string;
    grade_level: string | null;
    status_label: string;
    status: string;
    students_count: number;
    archived_at?: string | null;
    eligible_for_deletion_at?: string | null;
    is_eligible_for_deletion?: boolean;
};

defineProps<{
    classes: SchoolClass[];
    viewingArchived: boolean;
}>();

function restoreClass(schoolClass: SchoolClass): void {
    router.delete(`/classes/${schoolClass.ulid}/archive`, { preserveScroll: true });
}

/** «YYYY-MM-DD» → «DD/MM/YYYY», sem passar por `Date`. */
function formatDate(iso: string): string {
    const [year, month, day] = iso.split('-');

    return `${day}/${month}/${year}`;
}
</script>

<template>
    <Head title="Turmas" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <PageHeader
            :title="viewingArchived ? 'Turmas arquivadas' : 'Turmas'"
            :description="viewingArchived ? 'Turmas arquivadas — os dados continuam preservados.' : 'As turmas que leciona neste ano letivo.'"
        >
            <template #actions>
                <!-- A saída da lista de arquivadas é um botão, no mesmo sítio
                     das ações — nunca só texto discreto ou o «voltar» do
                     browser. -->
                <Button v-if="viewingArchived" as-child variant="outline">
                    <Link href="/classes"><ArrowLeft class="size-4" /> Voltar às turmas</Link>
                </Button>
                <template v-else>
                    <Button as-child variant="outline">
                        <Link href="/classes/schedule-setup"><CalendarPlus class="size-4" /> Configurar horários</Link>
                    </Button>
                    <Button as-child>
                        <Link href="/classes/create"><Plus class="size-4" /> Nova turma</Link>
                    </Button>
                </template>
            </template>
        </PageHeader>

        <!-- Numa linha própria e não nas ações do cabeçalho: lá não há wrap, e
             um terceiro botão empurrava «Nova turma» para fora do ecrã em
             mobile. -->
        <div v-if="!viewingArchived">
            <Button as-child variant="outline" size="sm">
                <Link href="/classes/archived"><Archive class="size-4" /> Ver turmas arquivadas</Link>
            </Button>
        </div>

        <EmptyState
            v-if="classes.length === 0"
            :title="viewingArchived ? 'Ainda não tem turmas arquivadas.' : 'Ainda não tem turmas.'"
            :icon="Users"
        >
            <template v-if="!viewingArchived" #action>
                <Button as-child>
                    <Link href="/classes/create"><Plus class="size-4" /> Criar a primeira turma</Link>
                </Button>
            </template>
        </EmptyState>

        <div v-else class="grid gap-3 sm:grid-cols-2">
            <div
                v-for="schoolClass in classes"
                :key="schoolClass.ulid"
                class="relative rounded-lg border border-border p-4 transition-colors hover:border-primary/40"
            >
                <Link :href="`/classes/${schoolClass.ulid}`" class="absolute inset-0 rounded-lg">
                    <span class="sr-only">Abrir turma {{ schoolClass.label }}</span>
                </Link>
                <div class="flex items-start justify-between gap-2">
                    <span class="text-base font-semibold">{{ schoolClass.label }}</span>
                    <div class="flex items-center gap-2">
                        <Badge variant="secondary" :class="statusToneClasses(schoolClass.status)">{{ schoolClass.status_label }}</Badge>
                        <Link
                            v-if="!viewingArchived"
                            :href="`/classes/${schoolClass.ulid}/edit`"
                            title="Editar turma"
                            :aria-label="`Editar turma ${schoolClass.label}`"
                            class="relative z-10 flex min-h-11 min-w-11 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                        >
                            <Pencil class="size-4" />
                        </Link>
                        <button
                            v-else
                            type="button"
                            title="Restaurar turma"
                            :aria-label="`Restaurar turma ${schoolClass.label}`"
                            class="relative z-10 flex min-h-11 min-w-11 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                            @click="restoreClass(schoolClass)"
                        >
                            <ArchiveRestore class="size-4" />
                        </button>
                    </div>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ schoolClass.subject }} · {{ schoolClass.academic_year }}<template v-if="schoolClass.grade_level"> · {{ schoolClass.grade_level }}</template>
                </p>
                <p class="mt-3 flex items-center gap-1.5 text-sm text-muted-foreground">
                    <Users class="size-4" /> {{ schoolClass.students_count }} alunos
                </p>
                <p v-if="viewingArchived" class="mt-1 text-xs text-muted-foreground">
                    <template v-if="schoolClass.is_eligible_for_deletion">Elegível para eliminação definitiva</template>
                    <template v-else-if="schoolClass.eligible_for_deletion_at">
                        Elegível para eliminação definitiva a partir de {{ formatDate(schoolClass.eligible_for_deletion_at) }}
                    </template>
                </p>
            </div>
        </div>

        <!-- Uma lista longa de arquivadas não deixa o botão do topo à vista. -->
        <div v-if="viewingArchived && classes.length > 6" class="flex justify-center">
            <Button as-child variant="outline">
                <Link href="/classes"><ArrowLeft class="size-4" /> Voltar às turmas</Link>
            </Button>
        </div>
    </div>
</template>
