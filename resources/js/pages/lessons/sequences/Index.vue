<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowLeft, ArrowUp, ListOrdered, Pencil, Plus, Send, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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

type Sequence = {
    ulid: string;
    name: string;
    subject: Option;
    academic_year: Option;
    grade_level: string | null;
    items: SequenceItem[];
    applicable_classes: Option[];
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

const applyOpen = ref(false);
const applying = ref<Sequence | null>(null);

const applyForm = useForm<{
    class_id: number | null;
    summary: boolean;
    resources: boolean;
    homework: boolean;
    private_notes: boolean;
}>({
    class_id: null,
    summary: true,
    resources: true,
    homework: true,
    // Never assumed — a private note written for one class's rhythm rarely
    // belongs verbatim to another.
    private_notes: false,
});

function openApply(sequence: Sequence): void {
    applying.value = sequence;
    applyForm.reset();
    applyForm.clearErrors();
    applyForm.class_id = sequence.applicable_classes[0]?.id ?? null;
    applyOpen.value = true;
}

function submitApply(): void {
    if (!applying.value) {
        return;
    }

    applyForm.post(`/lessons/sequences/${applying.value.ulid}/apply`, {
        preserveScroll: true,
        onSuccess: () => {
            applyOpen.value = false;
        },
    });
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
                description="Um plano reutilizável de conteúdo de aulas, aplicável a várias turmas da mesma disciplina e ano — cada aplicação cria uma cópia independente."
            />
            <Button class="min-h-11 shrink-0" @click="openCreate">
                <Plus class="size-4" /> Nova sequência
            </Button>
        </div>

        <div v-if="sequences.length === 0" class="rounded-xl border border-dashed p-6 text-center">
            <ListOrdered class="mx-auto size-8 text-muted-foreground" />
            <h2 class="mt-3 font-semibold">Ainda não tens sequências</h2>
            <p class="mx-auto mt-1 max-w-lg text-sm text-muted-foreground">
                Cria uma sequência para reutilizar o mesmo plano de aulas em turmas diferentes da mesma disciplina.
            </p>
        </div>

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
                            <Send class="size-4" /> Aplicar
                        </Button>
                        <Button variant="ghost" size="icon" aria-label="Editar" @click="openEdit(sequence)">
                            <Pencil class="size-4" />
                        </Button>
                        <Button variant="ghost" size="icon" aria-label="Eliminar" @click="destroy(sequence)">
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
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
                            Editar não altera os sumários já criados a partir desta sequência — cada aplicação é uma cópia independente.
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

        <!-- Apply -->
        <Dialog v-model:open="applyOpen">
            <DialogContent>
                <form class="space-y-4" @submit.prevent="submitApply">
                    <DialogHeader>
                        <DialogTitle>Aplicar «{{ applying?.name }}»</DialogTitle>
                        <DialogDescription>
                            Escreve numa turma compatível as próximas aulas ainda não lecionadas — cada uma recebe uma cópia independente, editável sem afetar esta sequência.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="apply-class">Turma</Label>
                        <select id="apply-class" v-model.number="applyForm.class_id" class="h-9 rounded-md border border-input bg-transparent px-3 text-sm">
                            <option v-for="option in applying?.applicable_classes ?? []" :key="option.id" :value="option.id">{{ option.label }}</option>
                        </select>
                        <InputError :message="applyForm.errors.class_id" />
                    </div>

                    <div class="grid gap-3">
                        <Label class="flex items-center gap-3 font-normal">
                            <Checkbox v-model="applyForm.summary" />
                            <span>Sumário</span>
                        </Label>
                        <Label class="flex items-center gap-3 font-normal">
                            <Checkbox v-model="applyForm.resources" />
                            <span>Recursos</span>
                        </Label>
                        <Label class="flex items-center gap-3 font-normal">
                            <Checkbox v-model="applyForm.homework" />
                            <span>TPC</span>
                        </Label>
                        <Label class="flex items-center gap-3 font-normal">
                            <Checkbox v-model="applyForm.private_notes" />
                            <span>Notas do professor</span>
                        </Label>
                        <p class="text-xs text-muted-foreground">
                            Um campo já preenchido numa aula só é substituído se a opção correspondente estiver ativa; caso contrário mantém-se como está.
                        </p>
                    </div>

                    <DialogFooter>
                        <Button type="submit" :disabled="applyForm.processing || !applyForm.class_id">Aplicar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>
