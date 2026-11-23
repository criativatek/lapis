<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import StudentCommentDialog from '@/components/instruments/StudentCommentDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { statusToneClasses } from '@/lib/statusTone';
import type { ResultsNote } from '@/types/resultsAnalysis';
import InstrumentTabs from './InstrumentTabs.vue';

type CommentItem = {
    id: number;
    code: string;
    label: string | null;
    points_possible: number | null;
    is_bonus: boolean;
    criterion_note: string | null;
};

type CommentStudent = {
    enrollment_ulid: string;
    class_number: number | null;
    name: string;
    comment: { body: string; lock_version: number } | null;
};

const props = defineProps<{
    instrument: {
        ulid: string;
        title: string;
        status: string;
        status_label: string;
        applied_on: string;
        class_label: string;
        class_ulid: string;
        period: string;
    };
    note: ResultsNote;
    can_edit: boolean;
    items: CommentItem[];
    /** Todos os alunos da grelha, pela ordem da turma, com o comentário se houver. */
    students?: CommentStudent[];
    links: { note: string; item_notes: string; grid: string; results: string };
    cancelled: boolean;
}>();

const appliedOnLabel = computed(() =>
    new Intl.DateTimeFormat('pt-PT', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' }).format(
        new Date(`${props.instrument.applied_on}T00:00:00Z`),
    ),
);

// ------------------------------------------------------------- Geral

const noteForm = useForm<{ body: string; lock_version: number }>({
    body: props.note.body,
    lock_version: props.note.lock_version,
});

function submitNote(): void {
    noteForm.put(props.links.note, {
        preserveScroll: true,
        // O servidor incrementa a versão a cada gravação. Sem a levar para o
        // formulário, a gravação seguinte NESTE separador seguia com a antiga
        // e era recusada como «alteradas noutra janela». Só aqui, no sucesso
        // da própria gravação: copiar a versão sempre que as props mudam
        // apagaria a deteção de um conflito verdadeiro com outra janela.
        onSuccess: () => {
            noteForm.lock_version = props.note.lock_version;
        },
    });
}

const noteUpdatedLabel = computed(() => {
    if (props.note.updated_at === null) {
        return null;
    }

    const formatted = new Intl.DateTimeFormat('pt-PT', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(props.note.updated_at));

    return props.note.updated_by ? `${formatted} · ${props.note.updated_by}` : formatted;
});

// ------------------------------------------------------------- Por questão / critério

const canEditItems = computed(() => props.can_edit && !props.cancelled);

const drafts = reactive<Record<number, string>>(Object.fromEntries(props.items.map((item) => [item.id, item.criterion_note ?? ''])));
const saved = reactive<Record<number, string>>({ ...drafts });
const busy = reactive<Record<number, boolean>>({});
const errors = reactive<Record<number, string | null>>({});

function itemChanged(item: CommentItem): boolean {
    return (drafts[item.id] ?? '').trim() !== (saved[item.id] ?? '');
}

function saveItem(item: CommentItem): void {
    if (busy[item.id] || !itemChanged(item)) {
        return;
    }

    const value = (drafts[item.id] ?? '').trim();

    busy[item.id] = true;
    errors[item.id] = null;

    router.put(props.links.item_notes, { notes: { [item.id]: value } }, {
        preserveScroll: true,
        preserveState: true,
        only: [],
        onSuccess: () => {
            saved[item.id] = value;
            drafts[item.id] = value;
        },
        onError: (received) => {
            errors[item.id] = Object.values(received)[0] ?? 'Não foi possível guardar o comentário.';
        },
        onFinish: () => {
            busy[item.id] = false;
        },
    });
}

// ------------------------------------------------------------- Por aluno

// Estado local: a gravação não recarrega a página (`only: []`), por isso a lista
// e as versões acompanham-se aqui, como as das perguntas acima.
const people = reactive<CommentStudent[]>((props.students ?? []).map((student) => ({ ...student })));
const withComment = computed(() => people.filter((student) => student.comment !== null));
const withoutComment = computed(() => people.filter((student) => student.comment === null));
const editingUlid = ref<string | null>(null);
const dialogOpen = ref(false);
const pickerValue = ref('');
const deleteBusy = ref<string | null>(null);
const deleteError = ref<string | null>(null);

const editing = computed(() => people.find((student) => student.enrollment_ulid === editingUlid.value) ?? null);

function studentUrl(student: CommentStudent): string {
    return `/instruments/${props.instrument.ulid}/students/${student.enrollment_ulid}/comment`;
}

function edit(student: CommentStudent): void {
    editingUlid.value = student.enrollment_ulid;
    dialogOpen.value = true;
}

function addFromPicker(): void {
    const student = people.find((entry) => entry.enrollment_ulid === pickerValue.value);

    pickerValue.value = '';

    if (student) {
        edit(student);
    }
}

function onStudentSaved(value: { body: string | null; lock_version: number }): void {
    const student = editing.value;

    if (student) {
        student.comment = value.body === null ? null : { body: value.body, lock_version: value.lock_version };
    }
}

function removeStudentComment(student: CommentStudent): void {
    if (deleteBusy.value !== null || student.comment === null) {
        return;
    }

    deleteBusy.value = student.enrollment_ulid;
    deleteError.value = null;

    router.put(studentUrl(student), { body: '', lock_version: student.comment.lock_version }, {
        preserveScroll: true,
        preserveState: true,
        only: [],
        onSuccess: () => {
            student.comment = null;
        },
        onError: (received) => {
            deleteError.value = Object.values(received)[0] ?? 'Não foi possível apagar o comentário.';
        },
        onFinish: () => {
            deleteBusy.value = null;
        },
    });
}

function formatPoints(points: number | null): string {
    return points === null ? '' : `${String(points).replace('.', ',')} pts`;
}
</script>

<template>
    <Head :title="`Comentários — ${instrument.title}`" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 space-y-1">
                <div class="flex flex-wrap items-start gap-3">
                    <Heading :title="instrument.title" :description="`${instrument.class_label} · ${instrument.period} · ${appliedOnLabel}`" />
                    <Badge class="mt-1" variant="secondary" :class="statusToneClasses(instrument.status)">
                        {{ instrument.status_label }}
                    </Badge>
                </div>
                <Link :href="`/classes/${instrument.class_ulid}`" class="text-sm text-muted-foreground hover:underline">
                    ← Voltar à turma
                </Link>
            </div>
        </div>

        <InstrumentTabs :ulid="instrument.ulid" active="comments" :cancelled="cancelled" />

        <p class="text-xs text-muted-foreground">
            Os comentários são privados: só o professor os vê. As observações são texto livre e podem conter
            informação identificável — reveja-as antes de partilhar um relatório.
        </p>

        <!-- Geral: a observação do professor sobre o elemento, sempre visível, mesmo sem resultados oficiais -->
        <section aria-labelledby="section-comments-general" class="space-y-2 rounded-lg border border-border bg-muted/10 p-4">
            <h2 id="section-comments-general" class="text-sm font-semibold">Geral</h2>
            <Textarea
                v-model="noteForm.body"
                :disabled="!can_edit"
                :readonly="!can_edit"
                rows="5"
                aria-label="Observações do professor"
                placeholder="Escreva aqui as suas observações sobre este instrumento."
            />
            <InputError :message="noteForm.errors.body" />
            <p class="text-xs text-muted-foreground">
                As observações ficam guardadas à parte e não se perdem quando os indicadores são recalculados.
            </p>
            <p v-if="noteUpdatedLabel" class="text-xs text-muted-foreground">Última alteração: {{ noteUpdatedLabel }}</p>
            <Button v-if="can_edit" type="button" size="sm" :disabled="noteForm.processing" @click="submitNote">
                Guardar observações
            </Button>
        </section>

        <!-- Por questão / critério: sobre a pergunta, nunca sobre um aluno -->
        <section aria-labelledby="section-comments-items" class="space-y-3 rounded-lg border border-border bg-muted/10 p-4">
            <div>
                <h2 id="section-comments-items" class="text-sm font-semibold">Por questão / critério</h2>
                <p class="text-xs text-muted-foreground">
                    Como se pontua cada questão, o que exige cada nível. Também aparecem na grelha de correção, ao passar o rato no código.
                </p>
            </div>
            <p v-if="items.length === 0" class="text-sm text-muted-foreground">Este elemento ainda não tem questões.</p>
            <ul v-else class="space-y-4">
                <li v-for="item in items" :key="item.id" class="space-y-1.5" :data-testid="`comment-item-${item.id}`">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="text-sm font-medium">{{ item.code }}</span>
                        <span v-if="item.label && item.label !== item.code" class="text-sm text-muted-foreground">{{ item.label }}</span>
                        <span class="text-xs text-muted-foreground">
                            {{ formatPoints(item.points_possible) }}<template v-if="item.is_bonus"> · bónus</template>
                        </span>
                    </div>
                    <Textarea
                        v-model="drafts[item.id]"
                        :disabled="!canEditItems"
                        :readonly="!canEditItems"
                        rows="2"
                        maxlength="2000"
                        :aria-label="`Comentário de ${item.code}`"
                        :placeholder="canEditItems ? 'Comentário sobre esta questão…' : ''"
                    />
                    <InputError :message="errors[item.id] ?? undefined" />
                    <Button
                        v-if="canEditItems"
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="busy[item.id] || !itemChanged(item)"
                        :aria-label="`Guardar comentário de ${item.code}`"
                        @click="saveItem(item)"
                    >
                        Guardar
                    </Button>
                </li>
            </ul>
        </section>

        <!-- Por aluno: o que o professor quer lembrar sobre um aluno NESTE elemento («Apanhado a copiar na Q2»).
             Privado; só sai no PDF da grelha se o professor o escolher. -->
        <section aria-labelledby="section-comments-students" class="space-y-3 rounded-lg border border-border bg-muted/10 p-4">
            <div>
                <h2 id="section-comments-students" class="text-sm font-semibold">Por aluno</h2>
                <p class="text-xs text-muted-foreground">
                    Comentários sobre um aluno neste elemento. Também aparecem na grelha (ícone junto ao nome) e no acompanhamento do aluno.
                    Não acompanham a grelha quando a copia para outra turma.
                </p>
            </div>

            <p v-if="withComment.length === 0" class="text-sm text-muted-foreground" data-testid="students-empty">
                Ainda não há comentários sobre alunos neste elemento.
            </p>
            <ul v-else class="space-y-3">
                <li
                    v-for="student in withComment"
                    :key="student.enrollment_ulid"
                    class="space-y-1 rounded-md border border-border bg-background p-3"
                    :data-testid="`comment-student-${student.enrollment_ulid}`"
                >
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <span class="text-sm font-medium">
                            <span class="text-muted-foreground">{{ student.class_number ?? '—' }}</span> {{ student.name }}
                        </span>
                        <span v-if="canEditItems" class="flex gap-2">
                            <Button type="button" size="sm" variant="outline" :aria-label="`Editar comentário sobre ${student.name}`" @click="edit(student)">
                                Editar
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                :disabled="deleteBusy !== null"
                                :aria-label="`Apagar comentário sobre ${student.name}`"
                                @click="removeStudentComment(student)"
                            >
                                Apagar
                            </Button>
                        </span>
                    </div>
                    <p class="text-sm whitespace-pre-line">{{ student.comment?.body }}</p>
                </li>
            </ul>
            <InputError :message="deleteError ?? undefined" />

            <div v-if="canEditItems && withoutComment.length > 0" class="flex flex-wrap items-center gap-2">
                <label for="comment-student-picker" class="text-sm text-muted-foreground">Adicionar comentário a…</label>
                <select
                    id="comment-student-picker"
                    v-model="pickerValue"
                    class="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                    data-testid="student-picker"
                    @change="addFromPicker"
                >
                    <option value="">Escolher aluno</option>
                    <option v-for="student in withoutComment" :key="student.enrollment_ulid" :value="student.enrollment_ulid">
                        {{ student.class_number ?? '—' }} · {{ student.name }}
                    </option>
                </select>
            </div>

            <StudentCommentDialog
                v-if="canEditItems && editing"
                v-model:open="dialogOpen"
                :url="studentUrl(editing)"
                :student-name="editing.name"
                :body="editing.comment?.body ?? null"
                :lock-version="editing.comment?.lock_version ?? 0"
                @saved="onStudentSaved"
            />
        </section>
    </div>
</template>
