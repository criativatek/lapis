<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowLeft, ArrowUp, ListOrdered, Pencil, Plus, Send, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { id: number; label: string };

type SequenceItem = {
    ulid: string;
    summary: string;
    private_notes: string | null;
    resources: string | null;
    homework: string | null;
};

type GroupOption = { id: number; label: string };

type ApplicableClass = Option & { groups: GroupOption[] };

type Application = {
    class_id: number;
    class_label: string;
    class_group_id: number | null;
    group_label: string | null;
    lessons_count: number;
    first_starts_at: string;
    last_starts_at: string;
};

type Sequence = {
    ulid: string;
    name: string;
    subject: Option;
    academic_year: Option;
    grade_level: string | null;
    items: SequenceItem[];
    applicable_classes: ApplicableClass[];
    applications: Application[];
};

// The form's own item shape stays strictly string (never null) — the server
// trims and nullifies blanks itself, matching LessonSummaryRequest's own
// convention for the same three optional fields.
type SequenceItemForm = {
    ulid: string | null;
    summary: string;
    private_notes: string;
    resources: string;
    homework: string;
};

const props = defineProps<{
    sequences: Sequence[];
    subjects: Option[];
    academicYears: Option[];
}>();

function emptyItem(): SequenceItemForm {
    return { ulid: null, summary: '', private_notes: '', resources: '', homework: '' };
}

// ------------------------------------------------------------ create/edit

const editOpen = ref(false);
const savedPromptUlid = ref<string | null>(null);
const editing = ref<Sequence | null>(null);

const editForm = useForm<{
    name: string;
    subject_id: number | null;
    academic_year_id: number | null;
    grade_level: string;
    items: SequenceItemForm[];
}>({
    name: '',
    subject_id: props.subjects[0]?.id ?? null,
    academic_year_id: props.academicYears[0]?.id ?? null,
    grade_level: '',
    items: [emptyItem()],
});

function openCreate(): void {
    editing.value = null;
    editForm.defaults({
        name: '',
        subject_id: props.subjects[0]?.id ?? null,
        academic_year_id: props.academicYears[0]?.id ?? null,
        grade_level: '',
        items: [emptyItem()],
    });
    editForm.reset();
    editForm.clearErrors();
    editOpen.value = true;
}

function openEdit(sequence: Sequence): void {
    editing.value = sequence;
    editForm.defaults({
        name: sequence.name,
        subject_id: sequence.subject.id,
        academic_year_id: sequence.academic_year.id,
        grade_level: sequence.grade_level ?? '',
        items: sequence.items.map((item) => ({
            ulid: item.ulid,
            summary: item.summary,
            private_notes: item.private_notes ?? '',
            resources: item.resources ?? '',
            homework: item.homework ?? '',
        })),
    });
    editForm.reset();
    editForm.clearErrors();
    editOpen.value = true;
}

function addItem(): void {
    editForm.items.push(emptyItem());
}

function removeItem(index: number): void {
    editForm.items.splice(index, 1);
}

function moveItem(index: number, offset: number): void {
    const target = index + offset;

    if (target < 0 || target >= editForm.items.length) {
        return;
    }

    const items = editForm.items;
    [items[index], items[target]] = [items[target], items[index]];
}

function submitEdit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;

            // Guardar a sequência nunca toca em aulas. Se ela já está
            // aplicada em algum sítio, diz-se isso mesmo e oferece-se o passo
            // seguinte — explícito — em vez de o fazer calado.
            if (editing.value && editing.value.applications.length > 0) {
                savedPromptUlid.value = editing.value.ulid;
            }
        },
    };

    if (editing.value) {
        editForm.put(`/lessons/sequences/${editing.value.ulid}`, options);
    } else {
        editForm.post('/lessons/sequences', options);
    }
}

function destroy(sequence: Sequence): void {
    if (confirm(`Eliminar a sequência "${sequence.name}"? Esta ação não pode ser desfeita.`)) {
        useForm({}).delete(`/lessons/sequences/${sequence.ulid}`, { preserveScroll: true });
    }
}

// ------------------------------------------------------------------ apply

type PlanStep = {
    kind: StepKind;
    lesson: {
        ulid: string | null;
        starts_at: string;
        ends_at: string | null;
        lesson_number: number | null;
        state_label: string;
        current_summary: string | null;
    };
    item: { ulid: string; position: number; summary: string } | null;
};

type StepKind =
    | 'fill'
    | 'update'
    | 'unchanged'
    | 'keep'
    | 'replace'
    | 'preserve'
    | 'closed'
    | 'release'
    | 'nothing_to_copy';

type Preview = {
    from: string;
    audience: { class_group_id: number | null; label: string };
    complete: boolean;
    plan_token: string;
    steps: PlanStep[];
    already_applied: { item: { ulid: string; position: number; summary: string }; lesson: { starts_at: string; state_label: string } }[];
    unplaced: { ulid: string; position: number; summary: string }[];
    counts: Record<string, number>;
    requires_replace_confirmation: boolean;
};

const kindLabels: Record<StepKind, string> = {
    fill: 'Nova',
    update: 'Atualizada',
    unchanged: 'Sem alterações',
    keep: 'Mantém a tua versão',
    replace: 'Substituir',
    preserve: 'Preservada — já preparada',
    closed: 'Não é alterada',
    release: 'Retirada (o elemento passou para outra aula)',
    nothing_to_copy: 'Nada a copiar',
};

const dayFormatter = new Intl.DateTimeFormat('pt-PT', {
    weekday: 'short',
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Europe/Lisbon',
});

const shortDateFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    timeZone: 'Europe/Lisbon',
});

function shortDate(iso: string): string {
    return shortDateFormatter.format(new Date(iso));
}

/** Hoje, no calendário de Lisboa, como `AAAA-MM-DD` (o que o `<input type="date">` e o servidor esperam). */
function todayInLisbon(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Lisbon' }).format(new Date());
}

const applyOpen = ref(false);
const applying = ref<Sequence | null>(null);
const applyClassId = ref<number | null>(null);
const applyGroupId = ref<string>('');
const applyFrom = ref(todayInLisbon());
const applyOptions = ref({ summary: true, resources: true, homework: true, private_notes: false });
// Nunca assumido — uma nota escrita para o ritmo de uma turma raramente serve
// à letra noutra.
const replaceUlids = ref<string[]>([]);
const confirmReplace = ref(false);
const preview = ref<Preview | null>(null);
const previewLoading = ref(false);
const previewFailure = ref<string | null>(null);
const submitting = ref(false);
const submitError = ref<string | null>(null);

const applyClass = computed(() => applying.value?.applicable_classes.find((entry) => entry.id === applyClassId.value) ?? null);

const anyOptionSelected = computed(() => Object.values(applyOptions.value).some(Boolean));

const replaceCount = computed(() => preview.value?.counts.replace ?? 0);

const countLabels: [string, string, string][] = [
    ['fill', 'nova', 'novas'],
    ['update', 'atualizada', 'atualizadas'],
    ['unchanged', 'sem alterações', 'sem alterações'],
    ['keep', 'mantida', 'mantidas'],
    ['preserve', 'preservada', 'preservadas'],
    ['replace', 'a substituir', 'a substituir'],
    ['release', 'retirada', 'retiradas'],
    ['closed', 'não alterada', 'não alteradas'],
    ['nothing_to_copy', 'sem nada a copiar', 'sem nada a copiar'],
];

/** Uma linha curta para a região viva: a lista inteira não se anuncia. */
const summaryLine = computed(() => {
    const counts = preview.value?.counts ?? {};

    return countLabels
        .filter(([key]) => (counts[key] ?? 0) > 0)
        .map(([key, one, many]) => `${counts[key]} ${counts[key] === 1 ? one : many}`)
        .join(' · ');
});

const canConfirm = computed(
    () =>
        preview.value !== null &&
        anyOptionSelected.value &&
        preview.value.complete &&
        !previewLoading.value &&
        !submitting.value &&
        (!preview.value.requires_replace_confirmation || confirmReplace.value),
);

function openApply(sequence: Sequence, application?: Application): void {
    applying.value = sequence;
    applyClassId.value = application?.class_id ?? sequence.applicable_classes[0]?.id ?? null;
    applyGroupId.value = application?.class_group_id != null ? String(application.class_group_id) : '';
    applyFrom.value = todayInLisbon();
    applyOptions.value = { summary: true, resources: true, homework: true, private_notes: false };
    replaceUlids.value = [];
    confirmReplace.value = false;
    preview.value = null;
    previewFailure.value = null;
    submitError.value = null;
    applyOpen.value = true;
}

function closeApply(): void {
    applyOpen.value = false;
}

function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

function requestBody(): Record<string, unknown> {
    return {
        class_id: applyClassId.value,
        class_group_id: applyGroupId.value === '' ? null : Number(applyGroupId.value),
        from: applyFrom.value,
        ...applyOptions.value,
        replace: replaceUlids.value,
    };
}

// Cada pré-visualização leva um número e só a ÚLTIMA pedida escreve no estado
// (a mesma guarda de InsertLessonDialog): mudar a data antes de a anterior
// responder deixava as duas a correr, e a mais antiga podia chegar depois e
// ativar «Confirmar» com um plano de outra data.
let latestRequest = 0;

async function loadPreview(): Promise<void> {
    if (!applyOpen.value || !applying.value || applyClassId.value === null || applyFrom.value === '' || !anyOptionSelected.value) {
        return;
    }

    const request = ++latestRequest;
    previewLoading.value = true;
    previewFailure.value = null;
    submitError.value = null;
    // A pré-visualização anterior NÃO se apaga: fica visível, atenuada, até
    // chegar a nova — a lista não desaparece a cada clique num radio.

    try {
        const response = await fetch(`/lessons/sequences/${applying.value.ulid}/preview`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(requestBody()),
        });

        const body = await response.json();

        if (request !== latestRequest) {
            return;
        }

        if (!response.ok) {
            const errors = (body?.errors ?? {}) as Record<string, string[]>;
            previewFailure.value =
                Object.values(errors)[0]?.[0] ?? (body?.message as string | undefined) ?? 'Não foi possível calcular a pré-visualização.';
            preview.value = null;

            return;
        }

        preview.value = body as Preview;

        if (!preview.value.requires_replace_confirmation) {
            confirmReplace.value = false;
        }
    } catch {
        if (request === latestRequest) {
            previewFailure.value = 'Não foi possível calcular a pré-visualização.';
            preview.value = null;
        }
    } finally {
        if (request === latestRequest) {
            previewLoading.value = false;
        }
    }
}

watch(
    [applyOpen, applyClassId, applyGroupId, applyFrom, () => ({ ...applyOptions.value }), replaceUlids],
    loadPreview,
    { deep: true },
);

// Trocar de turma limpa o grupo e as escolhas de substituição: pertencem à turma anterior.
watch(applyClassId, (_value, previous) => {
    // `openApply` define a turma (e o grupo) de uma só vez: só uma troca
    // feita pelo professor, a partir de uma turma já escolhida, limpa o resto.
    if (previous === null) {
        return;
    }

    applyGroupId.value = '';

    if (replaceUlids.value.length > 0) {
        replaceUlids.value = [];
    }

    confirmReplace.value = false;
});

function isReplacing(ulid: string | null): boolean {
    return ulid !== null && replaceUlids.value.includes(ulid);
}

function chooseReplace(ulid: string, replace: boolean): void {
    const without = replaceUlids.value.filter((entry) => entry !== ulid);
    replaceUlids.value = replace ? [...without, ulid] : without;
    // A confirmação vale para o conjunto que o professor viu: mudar o
    // conjunto pede-a de novo.
    confirmReplace.value = false;
}

function submitApply(): void {
    if (!applying.value || !preview.value || !canConfirm.value) {
        return;
    }

    submitting.value = true;
    submitError.value = null;

    router.post(
        `/lessons/sequences/${applying.value.ulid}/apply`,
        {
            ...requestBody(),
            confirm_replace: confirmReplace.value,
            plan_token: preview.value.plan_token,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                applyOpen.value = false;
            },
            onError: (errors: Record<string, string>) => {
                // O diálogo não fecha: o professor lê a razão e decide.
                submitError.value = Object.values(errors)[0] ?? 'Não foi possível aplicar a sequência.';
                // Um plano desatualizado (ou outra recusa): volta a mostrar o
                // plano atual antes de o professor confirmar de novo. Mantém a
                // mensagem — loadPreview limparia-a, por isso repõe-se depois.
                const message = submitError.value;
                void loadPreview().then(() => {
                    submitError.value = message;
                });
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

// Depois de guardar uma sequência já aplicada: o passo explícito.
const savedPromptSequence = computed(() => props.sequences.find((entry) => entry.ulid === savedPromptUlid.value) ?? null);

function applyAfterSave(): void {
    const sequence = savedPromptSequence.value;
    savedPromptUlid.value = null;

    if (sequence) {
        openApply(sequence, sequence.applications[0]);
    }
}
</script>

<template>
    <Head title="Sequências de aulas" />

    <main class="mx-auto w-full max-w-4xl space-y-6 p-4 pb-24 sm:p-6">
        <Button as-child variant="ghost" class="-ml-3 min-h-11">
            <Link href="/lessons">
                <ArrowLeft class="size-4" />
                Voltar às aulas
            </Link>
        </Button>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Sequências de aulas"
                description="Um plano reutilizável de conteúdo de aulas, aplicável a várias turmas da mesma disciplina e ano. Guardar uma sequência não altera nenhuma aula: só «Aplicar ao calendário» o faz."
            />
            <Button class="min-h-11 shrink-0" @click="openCreate">
                <Plus class="size-4" /> Nova sequência
            </Button>
        </div>

        <EmptyState
            v-if="sequences.length === 0"
            title="Ainda não tens sequências"
            description="Cria uma sequência para reutilizar o mesmo plano de aulas em turmas diferentes da mesma disciplina."
            :icon="ListOrdered"
        />

        <ul v-else class="space-y-3">
            <li v-for="sequence in sequences" :key="sequence.ulid" class="rounded-xl border bg-card p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1.5">
                        <h2 class="font-semibold">{{ sequence.name }}</h2>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <Badge variant="secondary">{{ sequence.subject.label }}</Badge>
                            <Badge variant="outline">{{ sequence.grade_level ?? 'Todos os anos' }}</Badge>
                            <Badge variant="outline">{{ sequence.academic_year.label }}</Badge>
                            <span class="text-xs text-muted-foreground">
                                {{ sequence.items.length === 1 ? '1 aula' : `${sequence.items.length} aulas` }}
                            </span>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="sequence.applicable_classes.length === 0 || sequence.items.length === 0"
                            @click="openApply(sequence)"
                        >
                            <Send class="size-4" /> Aplicar ao calendário
                        </Button>
                        <Button variant="ghost" size="icon" aria-label="Editar" @click="openEdit(sequence)">
                            <Pencil class="size-4" />
                        </Button>
                        <Button variant="ghost" size="icon" aria-label="Eliminar" @click="destroy(sequence)">
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
                <ul v-if="sequence.applications.length > 0" class="mt-3 space-y-2" :aria-label="`Onde «${sequence.name}» está aplicada`">
                    <li
                        v-for="application in sequence.applications"
                        :key="`${application.class_id}-${application.class_group_id ?? 'all'}`"
                        class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-muted/40 px-3 py-2 text-sm"
                    >
                        <span>
                            Aplicada em <strong>{{ application.class_label }}{{ application.group_label ? ` · ${application.group_label}` : '' }}</strong>
                            — {{ application.lessons_count === 1 ? '1 aula' : `${application.lessons_count} aulas` }},
                            {{ shortDate(application.first_starts_at) }} a {{ shortDate(application.last_starts_at) }}
                        </span>
                        <Button type="button" variant="outline" size="sm" class="min-h-11" @click="openApply(sequence, application)">
                            Aplicar alterações
                        </Button>
                    </li>
                </ul>
                <p v-if="sequence.applicable_classes.length === 0" class="mt-2 text-xs text-muted-foreground">
                    Sem turmas tuas compatíveis com esta disciplina{{ sequence.grade_level ? ' e ano' : '' }} de momento.
                </p>
            </li>
        </ul>

        <!-- Create/edit -->
        <Dialog v-model:open="editOpen">
            <DialogContent class="max-h-[85vh] max-w-2xl overflow-y-auto">
                <form class="space-y-4" @submit.prevent="submitEdit">
                    <DialogHeader>
                        <DialogTitle>{{ editing ? 'Editar sequência' : 'Nova sequência' }}</DialogTitle>
                        <DialogDescription>
                            Guardar altera só a sequência — nenhuma aula muda. As aulas só mudam quando escolheres «Aplicar ao calendário» e confirmares a pré-visualização.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="sequence-name">Nome</Label>
                        <Input id="sequence-name" v-model="editForm.name" placeholder="Ex.: Unidade — Frações" />
                        <InputError :message="editForm.errors.name" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="sequence-subject">Disciplina</Label>
                            <select id="sequence-subject" v-model.number="editForm.subject_id" class="h-9 rounded-md border border-input bg-transparent px-3 text-sm">
                                <option :value="null" disabled>Escolher…</option>
                                <option v-for="subject in subjects" :key="subject.id" :value="subject.id">{{ subject.label }}</option>
                            </select>
                            <InputError :message="editForm.errors.subject_id" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="sequence-year">Ano letivo</Label>
                            <select id="sequence-year" v-model.number="editForm.academic_year_id" class="h-9 rounded-md border border-input bg-transparent px-3 text-sm">
                                <option :value="null" disabled>Escolher…</option>
                                <option v-for="year in academicYears" :key="year.id" :value="year.id">{{ year.label }}</option>
                            </select>
                            <InputError :message="editForm.errors.academic_year_id" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="sequence-grade">Ano de escolaridade</Label>
                            <Input id="sequence-grade" v-model="editForm.grade_level" placeholder="Ex.: 7.º (opcional)" />
                            <InputError :message="editForm.errors.grade_level" />
                            <p class="text-xs text-muted-foreground">Em branco aplica-se a qualquer ano — útil no ensino superior.</p>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold">Aulas da sequência</h3>
                            <Button type="button" variant="outline" size="sm" @click="addItem">
                                <Plus class="size-4" /> Adicionar aula
                            </Button>
                        </div>
                        <InputError :message="editForm.errors.items" />

                        <div v-for="(item, index) in editForm.items" :key="index" class="space-y-3 rounded-xl border p-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-medium text-muted-foreground">Aula {{ index + 1 }}</span>
                                <div class="flex items-center gap-1">
                                    <Button type="button" variant="ghost" size="icon" :disabled="index === 0" aria-label="Mover para cima" @click="moveItem(index, -1)">
                                        <ArrowUp class="size-4" />
                                    </Button>
                                    <Button type="button" variant="ghost" size="icon" :disabled="index === editForm.items.length - 1" aria-label="Mover para baixo" @click="moveItem(index, 1)">
                                        <ArrowDown class="size-4" />
                                    </Button>
                                    <Button type="button" variant="ghost" size="icon" :disabled="editForm.items.length === 1" aria-label="Remover aula" @click="removeItem(index)">
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                            </div>

                            <div class="grid gap-2">
                                <Label :for="`item-summary-${index}`">Sumário</Label>
                                <textarea
                                    :id="`item-summary-${index}`"
                                    v-model="item.summary"
                                    rows="4"
                                    maxlength="16000"
                                    required
                                    placeholder="Texto do sumário…"
                                    class="w-full resize-y rounded-lg border border-input bg-background px-3 py-2 text-sm leading-relaxed"
                                />
                                <InputError :message="editForm.errors[`items.${index}.summary`]" />
                            </div>

                            <details :open="item.private_notes !== ''" class="group rounded-lg border">
                                <summary class="cursor-pointer px-3 py-2 text-sm font-medium">Notas do professor</summary>
                                <div class="border-t p-3">
                                    <textarea v-model="item.private_notes" rows="2" maxlength="16000" class="w-full resize-y rounded-lg border border-input bg-background px-3 py-2 text-sm leading-relaxed" />
                                </div>
                            </details>
                            <details :open="item.resources !== ''" class="group rounded-lg border">
                                <summary class="cursor-pointer px-3 py-2 text-sm font-medium">Recursos</summary>
                                <div class="border-t p-3">
                                    <textarea v-model="item.resources" rows="2" maxlength="16000" placeholder="Referência ou URL" class="w-full resize-y rounded-lg border border-input bg-background px-3 py-2 text-sm leading-relaxed" />
                                </div>
                            </details>
                            <details :open="item.homework !== ''" class="group rounded-lg border">
                                <summary class="cursor-pointer px-3 py-2 text-sm font-medium">TPC</summary>
                                <div class="border-t p-3">
                                    <textarea v-model="item.homework" rows="2" maxlength="16000" class="w-full resize-y rounded-lg border border-input bg-background px-3 py-2 text-sm leading-relaxed" />
                                </div>
                            </details>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" :disabled="editForm.processing">Guardar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Depois de guardar uma sequência que já está aplicada -->
        <Dialog :open="savedPromptUlid !== null" @update:open="(value: boolean) => { if (!value) savedPromptUlid = null; }">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Sequência guardada</DialogTitle>
                    <DialogDescription>
                        Sequência guardada. As aulas já preparadas a partir dela não mudaram.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" class="min-h-11" @click="savedPromptUlid = null">Agora não</Button>
                    <Button type="button" class="min-h-11" @click="applyAfterSave">Aplicar alterações ao calendário…</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Apply -->
        <Dialog v-model:open="applyOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form class="space-y-4" @submit.prevent="submitApply">
                    <DialogHeader>
                        <DialogTitle>Aplicar «{{ applying?.name }}» ao calendário</DialogTitle>
                        <DialogDescription>
                            Vê primeiro o que vai acontecer: nada é gravado até confirmares. As aulas anteriores à data escolhida e as aulas já lecionadas nunca são alteradas.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="apply-class">Turma</Label>
                            <select id="apply-class" v-model.number="applyClassId" class="min-h-11 rounded-md border border-input bg-transparent px-3 text-sm">
                                <option v-for="option in applying?.applicable_classes ?? []" :key="option.id" :value="option.id">{{ option.label }}</option>
                            </select>
                        </div>

                        <div v-if="(applyClass?.groups.length ?? 0) > 0" class="grid gap-2">
                            <Label for="apply-group">Participantes</Label>
                            <select id="apply-group" v-model="applyGroupId" class="min-h-11 rounded-md border border-input bg-transparent px-3 text-sm">
                                <option value="">Turma inteira</option>
                                <option v-for="group in applyClass?.groups ?? []" :key="group.id" :value="String(group.id)">{{ group.label }}</option>
                            </select>
                            <p class="text-xs text-muted-foreground">Cada grupo tem as suas próprias aulas: aplicar a T1 não toca em T2.</p>
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="apply-from">A partir de que data pretende aplicar esta sequência?</Label>
                        <Input id="apply-from" v-model="applyFrom" type="date" :min="todayInLisbon()" class="min-h-11" required />
                    </div>

                    <fieldset class="grid gap-1">
                        <legend class="mb-1 text-sm font-medium">Campos a copiar</legend>
                        <label class="flex min-h-11 items-center gap-3 text-sm">
                            <input v-model="applyOptions.summary" type="checkbox" class="size-5" />
                            <span>Sumário</span>
                        </label>
                        <label class="flex min-h-11 items-center gap-3 text-sm">
                            <input v-model="applyOptions.resources" type="checkbox" class="size-5" />
                            <span>Recursos</span>
                        </label>
                        <label class="flex min-h-11 items-center gap-3 text-sm">
                            <input v-model="applyOptions.homework" type="checkbox" class="size-5" />
                            <span>TPC</span>
                        </label>
                        <label class="flex min-h-11 items-center gap-3 text-sm">
                            <input v-model="applyOptions.private_notes" type="checkbox" class="size-5" />
                            <span>Notas do professor</span>
                        </label>
                    </fieldset>

                    <!-- Pré-visualização -->
                    <div class="rounded-lg border bg-muted/30 p-3 text-sm" data-testid="apply-preview" :aria-busy="previewLoading">
                        <p v-if="!anyOptionSelected" class="text-destructive">Escolhe pelo menos um campo a copiar.</p>
                        <p v-else-if="previewFailure" class="text-destructive" role="alert">{{ previewFailure }}</p>
                        <p v-if="previewLoading && !preview && !previewFailure" class="text-muted-foreground" role="status">A calcular a pré-visualização…</p>
                        <div v-if="preview" :class="previewLoading ? 'opacity-60' : ''">
                            <p class="font-medium" role="status" aria-live="polite" data-testid="apply-summary">
                                {{ previewLoading ? 'A recalcular…' : summaryLine || 'Nada a aplicar' }}
                            </p>
                            <p class="text-xs text-muted-foreground">{{ preview.audience.label }} — a partir de {{ shortDate(`${preview.from}T12:00:00Z`) }}</p>

                            <div v-if="preview.already_applied.length > 0" class="mt-2 text-xs text-muted-foreground">
                                <p class="font-medium">Já aplicadas antes desta data — não voltam a ser colocadas</p>
                                <ul class="mt-1 space-y-0.5">
                                    <li v-for="entry in preview.already_applied" :key="entry.item.ulid">
                                        {{ entry.item.position }}. {{ entry.item.summary }} — {{ dayFormatter.format(new Date(entry.lesson.starts_at)) }} ({{ entry.lesson.state_label }})
                                    </li>
                                </ul>
                            </div>

                            <ul class="mt-3 max-h-72 space-y-2 overflow-y-auto">
                                <li v-for="(step, index) in preview.steps" :key="`${step.lesson.starts_at}-${index}`" class="rounded-md border bg-background p-2" :data-kind="step.kind">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="tabular-nums">
                                            {{ dayFormatter.format(new Date(step.lesson.starts_at)) }}
                                            <span v-if="step.lesson.lesson_number" class="text-muted-foreground">· Lição {{ step.lesson.lesson_number }}</span>
                                        </span>
                                        <Badge :variant="step.kind === 'replace' ? 'destructive' : 'outline'">{{ step.kind === 'closed' ? `${step.lesson.state_label} — não é alterada` : kindLabels[step.kind] }}</Badge>
                                    </div>
                                    <p v-if="step.item" class="mt-1 text-muted-foreground">{{ step.item.position }}. {{ step.item.summary }}</p>
                                    <p v-if="step.lesson.current_summary && step.kind !== 'fill'" class="mt-1 text-xs text-muted-foreground">Agora na aula: {{ step.lesson.current_summary }}</p>

                                    <fieldset v-if="(step.kind === 'preserve' || step.kind === 'replace') && step.lesson.ulid" class="mt-2 flex flex-wrap gap-2">
                                        <legend class="sr-only">O que fazer a esta aula já preparada</legend>
                                        <label class="flex min-h-11 items-center gap-2 rounded-md border px-3">
                                            <input
                                                type="radio"
                                                :name="`replace-${step.lesson.ulid}`"
                                                :checked="!isReplacing(step.lesson.ulid)"
                                                @change="chooseReplace(step.lesson.ulid as string, false)"
                                            />
                                            <span>Preservar</span>
                                        </label>
                                        <label class="flex min-h-11 items-center gap-2 rounded-md border px-3">
                                            <input
                                                type="radio"
                                                :name="`replace-${step.lesson.ulid}`"
                                                :checked="isReplacing(step.lesson.ulid)"
                                                @change="chooseReplace(step.lesson.ulid as string, true)"
                                            />
                                            <span>Substituir</span>
                                        </label>
                                    </fieldset>
                                </li>
                            </ul>

                            <div v-if="!preview.complete" class="mt-3 rounded-md border border-destructive p-2 text-destructive" data-testid="apply-incomplete">
                                <p class="font-medium">
                                    Não há aulas suficientes no horário até ao fim do ano letivo para colocar {{ preview.unplaced.length }}
                                    {{ preview.unplaced.length === 1 ? 'elemento' : 'elementos' }} desta sequência.
                                </p>
                                <ul class="mt-1 list-disc pl-5 text-xs">
                                    <li v-for="item in preview.unplaced" :key="item.ulid">{{ item.position }}. {{ item.summary }}</li>
                                </ul>
                            </div>

                            <label v-if="preview.requires_replace_confirmation" class="mt-3 flex min-h-11 items-start gap-3 font-medium">
                                <input v-model="confirmReplace" type="checkbox" class="mt-1 size-5" data-testid="confirm-replace" />
                                <span>
                                    Confirmo que quero substituir {{ replaceCount }} {{ replaceCount === 1 ? 'aula já preparada' : 'aulas já preparadas' }}.
                                    O conteúdo atual dessas aulas será substituído.
                                </span>
                            </label>
                        </div>
                    </div>

                    <p v-if="submitError" class="text-sm text-destructive" role="alert">{{ submitError }}</p>

                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" class="min-h-11" @click="closeApply">Cancelar</Button>
                        <Button type="submit" class="min-h-11" :disabled="!canConfirm">
                            {{ submitting ? 'A aplicar…' : 'Confirmar aplicação' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>
