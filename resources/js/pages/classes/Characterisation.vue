<script setup lang="ts">
/**
 * Caracterização pedagógica da turma — three zones, per
 * docs/superpowers/specs/2026-09-20-class-pedagogical-characterisation.md §7:
 * a secondary class-level card, a search + import action bar, and the list of
 * students, one `Collapsible` each, only one open at a time.
 *
 * Saving is always explicit (a button). No autosave, no save-on-blur — the
 * spec is emphatic that a teacher must choose the moment a sentence about a
 * child becomes what is written down.
 */
import { Head, router } from '@inertiajs/vue3';
import { ChevronDown, FileUp, History, Search } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import CharacterisationImportDialog from '@/pages/classes/partials/CharacterisationImportDialog.vue';

type Revision = {
    ulid: string;
    created_at: string | null;
    author: string | null;
    source_label: string;
    changed_labels: string[];
};

type SourceMeasure = {
    ulid: string;
    level_label: string | null;
    code_label: string | null;
    raw_token: string;
    unresolved_annotations: string[];
};

type StudentSections = {
    summary: string | null;
    strengths: string | null;
    interests: string | null;
    needs: string | null;
    barriers: string | null;
    participation: string | null;
};

/** As secções guardadas vêm de `null` para «vazio»; o `Textarea` só aceita
 * `string | number`, por isso os rascunhos trabalham sempre em `''`. */
type DraftSections = Record<keyof StudentSections, string>;

function toDraft(sections: StudentSections): DraftSections {
    return {
        summary: sections.summary ?? '',
        strengths: sections.strengths ?? '',
        interests: sections.interests ?? '',
        needs: sections.needs ?? '',
        barriers: sections.barriers ?? '',
        participation: sections.participation ?? '',
    };
}

type Student = {
    enrollment_ulid: string;
    name: string;
    class_number: number | null;
    sections: StudentSections;
    has_characterisation: boolean;
    last_updated_at: string | null;
    updated_by: string | null;
    source_measures: SourceMeasure[];
    revisions: Revision[];
};

const props = defineProps<{
    schoolClass: {
        ulid: string;
        label: string;
        subject: string | null;
        academic_year: string | null;
    };
    classCharacterisation: {
        summary: string | null;
        last_updated_at: string | null;
        updated_by: string | null;
        revisions: Revision[];
    };
    students: Student[];
    sections: { key: string; label: string }[];
    can: { update: boolean };
}>();

const dateTimeFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

function formatDateTime(iso: string | null): string | null {
    if (iso === null) {
        return null;
    }

    return dateTimeFormatter.format(new Date(iso));
}

// --- Cartão da turma (secundário, recolhido por omissão) -------------------

const classCardOpen = ref(false);
const classSummaryDraft = ref(props.classCharacterisation.summary ?? '');
const savingClassSummary = ref(false);

function saveClassSummary(): void {
    savingClassSummary.value = true;

    router.put(
        `/classes/${props.schoolClass.ulid}/characterisation`,
        { summary: classSummaryDraft.value },
        {
            preserveScroll: true,
            onFinish: () => {
                savingClassSummary.value = false;
            },
        },
    );
}

// --- Pesquisa ---------------------------------------------------------------

const searchTerm = ref('');

function normalize(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

const filteredStudents = computed(() => {
    const term = normalize(searchTerm.value.trim());

    if (term === '') {
        return props.students;
    }

    return props.students.filter((student) => normalize(student.name).includes(term));
});

// --- Lista de alunos — só um aberto de cada vez -----------------------------

const openEnrollmentUlid = ref<string | null>(null);

function toggleStudent(enrollmentUlid: string): void {
    openEnrollmentUlid.value = openEnrollmentUlid.value === enrollmentUlid ? null : enrollmentUlid;
}

// Um rascunho por aluno, iniciado a partir das secções já guardadas. Não se
// grava nada até o professor carregar em «Guardar» — nem ao fechar o
// `Collapsible`, nem ao sair do campo.
const drafts = reactive<Record<string, DraftSections>>(
    Object.fromEntries(props.students.map((student) => [student.enrollment_ulid, toDraft(student.sections)])),
);

const savingStudent = ref<string | null>(null);
const historyOpen = reactive<Record<string, boolean>>({});

function toggleHistory(enrollmentUlid: string): void {
    historyOpen[enrollmentUlid] = !historyOpen[enrollmentUlid];
}

function saveStudent(student: Student): void {
    savingStudent.value = student.enrollment_ulid;

    router.put(
        `/classes/${props.schoolClass.ulid}/students/${student.enrollment_ulid}/characterisation`,
        { ...drafts[student.enrollment_ulid] },
        {
            preserveScroll: true,
            onFinish: () => {
                savingStudent.value = null;
            },
        },
    );
}

const importDialogOpen = ref(false);
</script>

<template>
    <Head :title="`Caracterização pedagógica — ${schoolClass.label}`" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            :title="`Caracterização pedagógica — ${schoolClass.label}`"
            :description="[schoolClass.subject, schoolClass.academic_year].filter(Boolean).join(' · ')"
        />

        <!-- Zona 1: cartão da turma — recolhido, secundário. Não substitui a
             caracterização individual, por isso não pesa visualmente como ela. -->
        <Collapsible v-model:open="classCardOpen">
            <Card class="border-dashed">
                <CollapsibleTrigger as-child>
                    <button type="button" class="w-full text-left">
                        <CardHeader class="flex-row items-center justify-between">
                            <div class="space-y-0.5">
                                <CardTitle class="text-sm font-medium text-muted-foreground">
                                    Caracterização da turma
                                </CardTitle>
                                <p v-if="classCharacterisation.last_updated_at" class="text-xs text-muted-foreground">
                                    Atualizada em {{ formatDateTime(classCharacterisation.last_updated_at) }}
                                    <template v-if="classCharacterisation.updated_by">
                                        por {{ classCharacterisation.updated_by }}
                                    </template>
                                </p>
                            </div>
                            <ChevronDown
                                class="size-4 text-muted-foreground transition-transform"
                                :class="{ 'rotate-180': classCardOpen }"
                            />
                        </CardHeader>
                    </button>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <CardContent class="space-y-3">
                        <Textarea
                            v-model="classSummaryDraft"
                            :disabled="!can.update"
                            rows="4"
                            placeholder="Aspetos gerais da turma — clima, dinâmica, contexto…"
                        />
                        <div class="flex justify-end">
                            <Button
                                v-if="can.update"
                                type="button"
                                size="sm"
                                variant="outline"
                                :disabled="savingClassSummary"
                                @click="saveClassSummary"
                            >
                                Guardar
                            </Button>
                        </div>
                    </CardContent>
                </CollapsibleContent>
            </Card>
        </Collapsible>

        <!-- Zona 2: barra de ações -->
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-[200px]">
                <Search class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input v-model="searchTerm" placeholder="Pesquisar aluno…" class="pl-8" />
            </div>
            <Button v-if="can.update" type="button" variant="outline" size="sm" @click="importDialogOpen = true">
                <FileUp class="size-4" /> Importar caracterização
            </Button>
        </div>

        <!-- Zona 3: lista de alunos -->
        <div class="space-y-2">
            <p v-if="filteredStudents.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                Nenhum aluno corresponde à pesquisa.
            </p>

            <Collapsible
                v-for="student in filteredStudents"
                :key="student.enrollment_ulid"
                :open="openEnrollmentUlid === student.enrollment_ulid"
                @update:open="() => toggleStudent(student.enrollment_ulid)"
            >
                <Card>
                    <CollapsibleTrigger as-child>
                        <button type="button" class="w-full text-left">
                            <CardHeader class="flex-row items-center justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="w-6 shrink-0 text-right text-sm tabular-nums text-muted-foreground">
                                        {{ student.class_number ?? '—' }}
                                    </span>
                                    <div class="min-w-0 space-y-0.5">
                                        <p class="truncate text-sm font-medium">{{ student.name }}</p>
                                        <p class="text-xs text-muted-foreground">
                                            <template v-if="student.last_updated_at">
                                                Atualizado em {{ formatDateTime(student.last_updated_at) }}
                                                <template v-if="student.updated_by">
                                                    por {{ student.updated_by }}
                                                </template>
                                            </template>
                                            <Badge v-else variant="outline" class="font-normal">
                                                Sem caracterização
                                            </Badge>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <Badge v-if="student.source_measures.length > 0" variant="secondary">
                                        {{ student.source_measures.length }}
                                        {{ student.source_measures.length === 1 ? 'medida de origem' : 'medidas de origem' }}
                                    </Badge>
                                    <ChevronDown
                                        class="size-4 text-muted-foreground transition-transform"
                                        :class="{ 'rotate-180': openEnrollmentUlid === student.enrollment_ulid }"
                                    />
                                </div>
                            </CardHeader>
                        </button>
                    </CollapsibleTrigger>

                    <CollapsibleContent>
                        <CardContent class="space-y-4">
                            <div class="space-y-1 rounded-md bg-muted/50 p-3 text-xs text-muted-foreground">
                                <p>
                                    Registe aspetos relevantes para o acompanhamento pedagógico do aluno. A
                                    informação pode ser atualizada ao longo do ano letivo.
                                </p>
                            </div>

                            <div
                                v-if="student.source_measures.length > 0"
                                class="space-y-2 rounded-md border border-dashed p-3"
                            >
                                <p class="text-xs font-medium text-muted-foreground">Medidas de origem</p>
                                <ul class="space-y-1.5">
                                    <li v-for="measure in student.source_measures" :key="measure.ulid" class="text-sm">
                                        <span>{{ [measure.level_label, measure.code_label].filter(Boolean).join(' · ') || 'Sigla por confirmar' }}</span>
                                        <span class="block text-xs text-muted-foreground italic">
                                            o ficheiro indicava: {{ measure.raw_token }}
                                        </span>
                                        <span v-if="measure.unresolved_annotations.length > 0" class="mt-1 flex flex-wrap gap-1">
                                            <Badge
                                                v-for="annotation in measure.unresolved_annotations"
                                                :key="annotation"
                                                variant="outline"
                                                class="text-muted-foreground font-normal"
                                            >
                                                {{ annotation }}
                                            </Badge>
                                        </span>
                                    </li>
                                </ul>
                            </div>

                            <div class="grid gap-4">
                                <div v-for="section in sections" :key="section.key" class="grid gap-1.5">
                                    <Label :for="`${student.enrollment_ulid}-${section.key}`">{{ section.label }}</Label>
                                    <Textarea
                                        :id="`${student.enrollment_ulid}-${section.key}`"
                                        v-model="drafts[student.enrollment_ulid][section.key as keyof DraftSections]"
                                        :disabled="!can.update"
                                        rows="3"
                                    />
                                </div>
                            </div>

                            <p class="text-xs text-muted-foreground">
                                Evite incluir dados pessoais que não sejam necessários para o acompanhamento
                                pedagógico.
                            </p>

                            <div class="flex items-center justify-between gap-2 border-t pt-3">
                                <Collapsible :open="historyOpen[student.enrollment_ulid] ?? false">
                                    <CollapsibleTrigger as-child>
                                        <button
                                            type="button"
                                            class="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                            @click="toggleHistory(student.enrollment_ulid)"
                                        >
                                            <History class="size-3.5" />
                                            Ver histórico
                                            <span v-if="student.revisions.length > 0">({{ student.revisions.length }})</span>
                                        </button>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent>
                                        <ul class="mt-2 space-y-1.5">
                                            <li
                                                v-if="student.revisions.length === 0"
                                                class="text-xs text-muted-foreground"
                                            >
                                                Sem revisões anteriores.
                                            </li>
                                            <li
                                                v-for="revision in student.revisions"
                                                :key="revision.ulid"
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{ formatDateTime(revision.created_at) }} ·
                                                {{ revision.author ?? 'Autor desconhecido' }} ·
                                                {{ revision.source_label }} —
                                                {{ revision.changed_labels.join(', ') }}
                                            </li>
                                        </ul>
                                    </CollapsibleContent>
                                </Collapsible>

                                <Button
                                    v-if="can.update"
                                    type="button"
                                    size="sm"
                                    :disabled="savingStudent === student.enrollment_ulid"
                                    @click="saveStudent(student)"
                                >
                                    Guardar
                                </Button>
                            </div>
                        </CardContent>
                    </CollapsibleContent>
                </Card>
            </Collapsible>
        </div>

        <CharacterisationImportDialog
            v-model:open="importDialogOpen"
            :class-ulid="schoolClass.ulid"
            :students="students.map((student) => ({ ulid: student.enrollment_ulid, name: student.name, class_number: student.class_number }))"
        />
    </div>
</template>
