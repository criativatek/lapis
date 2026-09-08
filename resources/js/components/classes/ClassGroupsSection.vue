<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    ArchiveRestore,
    Archive,
    ArrowLeftRight,
    ArrowRight,
    ChevronDown,
    ChevronUp,
    Pencil,
    Plus,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
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
import NativeSelect from '@/components/ui/NativeSelect.vue';

/**
 * A secção «Grupos» do ecrã da turma: T1, T2, e quem está em cada um.
 *
 * SEM ASSISTENTE. Tudo o que aqui se faz — criar, renomear, ordenar, arquivar,
 * distribuir, mover, permutar — acontece nesta página, sem sair dela e sem
 * depender do botão «voltar» do browser. A turma continua sempre reeditável.
 *
 * A DATA SÓ APARECE QUANDO É PRECISA. Distribuir alunos que ainda não têm grupo
 * não pergunta nada: o servidor sabe que a pertença começa no início do ano
 * letivo, ou no dia em que o aluno entrou. Mover alguém que JÁ está num grupo é
 * outra coisa — fecha uma janela e abre outra — e aí a data é obrigatória, com
 * a mesma caixa explicativa do `effective_from` dos tempos do horário.
 */

export type ClassGroup = {
    ulid: string;
    id: number;
    label: string;
    position: number;
    archived: boolean;
    members_count: number;
};

export type GroupedStudent = {
    ulid: string;
    id: number;
    name: string;
    class_number: number | null;
    class_group_id: number | null;
    /**
     * Desde quando a pertença atual vale. Num aluno de ingresso tardio é o dia
     * em que ele entrou na turma, e é a data mais cedo em que a pertença dele
     * pode ser corrigida — antes disso o servidor recusa, com razão.
     */
    class_group_since: string | null;
};

const props = defineProps<{
    classUlid: string;
    groups: ClassGroup[];
    students: GroupedStudent[];
    /**
     * A data que os diálogos com «válida a partir de» oferecem por omissão.
     * Vem do servidor — «hoje», limitado ao ano letivo desta turma — porque o
     * relógio do browser não sabe quando o ano começa, e em setembro ofereceria
     * uma data que a própria ação recusaria.
     */
    defaultDate: string;
}>();

const activeGroups = computed(() => props.groups.filter((group) => !group.archived));
const archivedGroups = computed(() => props.groups.filter((group) => group.archived));

const ungrouped = computed(() =>
    props.students.filter((student) => student.class_group_id === null),
);

function studentsOf(group: ClassGroup): GroupedStudent[] {
    return props.students.filter((student) => student.class_group_id === group.id);
}

function groupLabel(groupId: number | null): string {
    if (groupId === null) {
        return 'Sem grupo';
    }

    return props.groups.find((group) => group.id === groupId)?.label ?? 'Sem grupo';
}

// ------------------------------------------------------------ criar/renomear

const groupForm = useForm({ label: '' });
const editingGroupUlid = ref<string | null>(null);

function submitGroup(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            groupForm.reset();
            editingGroupUlid.value = null;
        },
    };

    if (editingGroupUlid.value === null) {
        groupForm.post(`/classes/${props.classUlid}/groups`, options);

        return;
    }

    groupForm.put(
        `/classes/${props.classUlid}/groups/${editingGroupUlid.value}`,
        options,
    );
}

function startRename(group: ClassGroup): void {
    editingGroupUlid.value = group.ulid;
    groupForm.label = group.label;
    groupForm.clearErrors();
}

function cancelGroupForm(): void {
    editingGroupUlid.value = null;
    groupForm.reset();
    groupForm.clearErrors();
}

// ------------------------------------------------------------------- ordenar

function moveGroup(group: ClassGroup, direction: -1 | 1): void {
    const ordered = [...activeGroups.value];
    const index = ordered.findIndex((candidate) => candidate.ulid === group.ulid);
    const target = index + direction;

    if (index === -1 || target < 0 || target >= ordered.length) {
        return;
    }

    [ordered[index], ordered[target]] = [ordered[target], ordered[index]];

    router.put(
        `/classes/${props.classUlid}/groups/order`,
        // Os arquivados vão no fim e mantêm-se entre si: continuam a existir e
        // a ter posição, só não se reordenam à mão.
        {
            ulids: [
                ...ordered.map((candidate) => candidate.ulid),
                ...archivedGroups.value.map((candidate) => candidate.ulid),
            ],
        },
        { preserveScroll: true },
    );
}

// ---------------------------------------------------- apagar/arquivar/repor

function removeGroup(group: ClassGroup): void {
    if (
        confirm(
            `Apagar o grupo ${group.label}? Só é possível enquanto não tiver alunos nem tempos no horário.`,
        )
    ) {
        router.delete(`/classes/${props.classUlid}/groups/${group.ulid}`, {
            preserveScroll: true,
        });
    }
}

function archiveGroup(group: ClassGroup): void {
    if (
        confirm(
            `Arquivar o grupo ${group.label}? Deixa de aceitar alunos e de poder ser escolhido no horário. As aulas e os sumários que já o referem continuam a lê-lo.`,
        )
    ) {
        router.post(
            `/classes/${props.classUlid}/groups/${group.ulid}/archive`,
            {},
            { preserveScroll: true },
        );
    }
}

function restoreGroup(group: ClassGroup): void {
    router.delete(`/classes/${props.classUlid}/groups/${group.ulid}/archive`, {
        preserveScroll: true,
    });
}

// ------------------------------------------------- distribuição inicial

const assignmentDialogOpen = ref(false);
const assignmentForm = useForm<{ assignments: { enrollment_id: number; class_group_id: number }[] }>({
    assignments: [],
});
// Inscrição => grupo escolhido no diálogo. Um aluno que fique por atribuir
// simplesmente não entra no envio: «Sem grupo» é um estado legítimo, e não uma
// linha por escrever.
const draftAssignments = ref<Record<number, number | null>>({});

function openAssignmentDialog(): void {
    draftAssignments.value = Object.fromEntries(
        ungrouped.value.map((student) => [student.id, null]),
    );
    assignmentForm.clearErrors();
    assignmentDialogOpen.value = true;
}

function submitAssignments(): void {
    assignmentForm.assignments = Object.entries(draftAssignments.value)
        .filter(([, groupId]) => groupId !== null)
        .map(([enrollmentId, groupId]) => ({
            enrollment_id: Number(enrollmentId),
            class_group_id: Number(groupId),
        }));

    assignmentForm.post(`/classes/${props.classUlid}/groups/assignments`, {
        preserveScroll: true,
        onSuccess: () => {
            assignmentDialogOpen.value = false;
            draftAssignments.value = {};
        },
    });
}

const assignmentCount = computed(
    () => Object.values(draftAssignments.value).filter((groupId) => groupId !== null).length,
);

// ------------------------------------------------------------------- mover

const moveDialogOpen = ref(false);
const movingStudent = ref<GroupedStudent | null>(null);
const moveForm = useForm<{
    enrollment_id: number | null;
    class_group_id: number | null;
    effective_from: string;
}>({
    enrollment_id: null,
    class_group_id: null,
    effective_from: '',
});

function openMoveDialog(student: GroupedStudent): void {
    movingStudent.value = student;
    moveForm.enrollment_id = student.id;
    moveForm.class_group_id =
        activeGroups.value.find((group) => group.id !== student.class_group_id)?.id ?? null;
    // A data mais tardia entre a do ecrã e o início da pertença atual: corrigir
    // a pertença de um aluno de ingresso tardio numa data anterior ao dia em que
    // ele entrou seria recusado, e o professor não tem por que adivinhá-la.
    // Continua a ser só um valor por omissão — ele pode mudá-lo, e é o servidor
    // que valida seja qual for o que daqui sair.
    moveForm.effective_from =
        student.class_group_since !== null && student.class_group_since > props.defaultDate
            ? student.class_group_since
            : props.defaultDate;
    moveForm.clearErrors();
    moveDialogOpen.value = true;
}

function submitMove(): void {
    moveForm.post(`/classes/${props.classUlid}/groups/moves`, {
        preserveScroll: true,
        onSuccess: () => {
            moveDialogOpen.value = false;
            movingStudent.value = null;
        },
    });
}

// ----------------------------------------------------------------- permutar

const swapDialogOpen = ref(false);
const swapForm = useForm<{
    first_enrollment_id: number | null;
    second_enrollment_id: number | null;
    effective_from: string;
}>({
    first_enrollment_id: null,
    second_enrollment_id: null,
    effective_from: '',
});

const groupedStudents = computed(() =>
    props.students.filter((student) => student.class_group_id !== null),
);

const swapFirst = computed(() =>
    groupedStudents.value.find(
        (student) => student.id === swapForm.first_enrollment_id,
    ) ?? null,
);
const swapSecond = computed(() =>
    groupedStudents.value.find(
        (student) => student.id === swapForm.second_enrollment_id,
    ) ?? null,
);

function openSwapDialog(): void {
    swapForm.first_enrollment_id = groupedStudents.value[0]?.id ?? null;
    swapForm.second_enrollment_id =
        groupedStudents.value.find(
            (student) => student.class_group_id !== groupedStudents.value[0]?.class_group_id,
        )?.id ?? null;
    swapForm.effective_from = props.defaultDate;
    swapForm.clearErrors();
    swapDialogOpen.value = true;
}

function submitSwap(): void {
    swapForm.post(`/classes/${props.classUlid}/groups/swaps`, {
        preserveScroll: true,
        onSuccess: () => {
            swapDialogOpen.value = false;
        },
    });
}
</script>

<template>
    <section class="space-y-4" aria-labelledby="class-groups-heading">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="class-groups-heading" class="text-sm font-semibold">
                    Grupos
                </h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Para as turmas que, em certos tempos, funcionam divididas —
                    T1 e T2, por exemplo. A turma continua a ser uma só: a
                    avaliação, a pauta e os relatórios são sempre de todos os
                    alunos.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="ungrouped.length && activeGroups.length"
                    type="button"
                    variant="outline"
                    size="sm"
                    class="min-h-10"
                    @click="openAssignmentDialog"
                >
                    <Plus class="size-4" /> Distribuir alunos
                </Button>
                <Button
                    v-if="groupedStudents.length > 1 && activeGroups.length > 1"
                    type="button"
                    variant="outline"
                    size="sm"
                    class="min-h-10"
                    @click="openSwapDialog"
                >
                    <ArrowLeftRight class="size-4" /> Permutar alunos
                </Button>
            </div>
        </div>

        <ul v-if="activeGroups.length" class="grid gap-3 sm:grid-cols-2">
            <li
                v-for="(group, index) in activeGroups"
                :key="group.ulid"
                class="rounded-lg border p-3"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-medium">{{ group.label }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ group.members_count }}
                            {{ group.members_count === 1 ? 'aluno' : 'alunos' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="min-h-10 min-w-10"
                            :disabled="index === 0"
                            :aria-label="`Subir o grupo ${group.label}`"
                            @click="moveGroup(group, -1)"
                        >
                            <ChevronUp class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="min-h-10 min-w-10"
                            :disabled="index === activeGroups.length - 1"
                            :aria-label="`Descer o grupo ${group.label}`"
                            @click="moveGroup(group, 1)"
                        >
                            <ChevronDown class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="min-h-10 min-w-10"
                            :aria-label="`Renomear o grupo ${group.label}`"
                            @click="startRename(group)"
                        >
                            <Pencil class="size-4" />
                        </Button>
                        <Button
                            v-if="group.members_count === 0"
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="min-h-10 min-w-10 text-destructive"
                            :aria-label="`Apagar o grupo ${group.label}`"
                            @click="removeGroup(group)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="min-h-10 min-w-10"
                            :aria-label="`Arquivar o grupo ${group.label}`"
                            @click="archiveGroup(group)"
                        >
                            <Archive class="size-4" />
                        </Button>
                    </div>
                </div>

                <ul
                    v-if="studentsOf(group).length"
                    class="mt-3 divide-y border-t text-sm"
                >
                    <li
                        v-for="student in studentsOf(group)"
                        :key="student.ulid"
                        class="flex items-center justify-between gap-2 py-2"
                    >
                        <span class="min-w-0 truncate">
                            <span class="text-muted-foreground"
                                >{{ student.class_number ?? '—' }}.</span
                            >
                            {{ student.name }}
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="min-h-10"
                            :aria-label="`Mover ${student.name} de grupo`"
                            @click="openMoveDialog(student)"
                        >
                            <ArrowRight class="size-4" /> Mover
                        </Button>
                    </li>
                </ul>
                <p v-else class="mt-3 border-t pt-3 text-xs text-muted-foreground">
                    Ainda sem alunos.
                </p>
            </li>
        </ul>
        <p
            v-else
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
        >
            Esta turma não está desdobrada em grupos. Cria um grupo se, em
            alguns tempos, ela funcionar dividida.
        </p>

        <div
            v-if="activeGroups.length && ungrouped.length"
            class="rounded-lg border border-dashed p-3"
        >
            <p class="text-sm font-medium">
                Sem grupo ({{ ungrouped.length }})
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                Nem todos os alunos têm de pertencer a um grupo. Estes
                participam nos tempos da turma inteira.
            </p>
            <ul class="mt-2 divide-y border-t text-sm">
                <li
                    v-for="student in ungrouped"
                    :key="student.ulid"
                    class="flex items-center justify-between gap-2 py-2"
                >
                    <span class="min-w-0 truncate">
                        <span class="text-muted-foreground"
                            >{{ student.class_number ?? '—' }}.</span
                        >
                        {{ student.name }}
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="min-h-10"
                        :aria-label="`Atribuir grupo a ${student.name}`"
                        @click="openMoveDialog(student)"
                    >
                        <ArrowRight class="size-4" /> Atribuir
                    </Button>
                </li>
            </ul>
        </div>

        <details v-if="archivedGroups.length" class="rounded-lg border p-3">
            <summary class="cursor-pointer text-sm font-medium">
                Grupos arquivados ({{ archivedGroups.length }})
            </summary>
            <ul class="mt-2 divide-y border-t text-sm">
                <li
                    v-for="group in archivedGroups"
                    :key="group.ulid"
                    class="flex items-center justify-between gap-2 py-2"
                >
                    <span>{{ group.label }}</span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="min-h-10"
                        @click="restoreGroup(group)"
                    >
                        <ArchiveRestore class="size-4" /> Repor
                    </Button>
                </li>
            </ul>
        </details>

        <form
            class="space-y-3 rounded-lg border bg-muted/20 p-4"
            @submit.prevent="submitGroup"
        >
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-medium">
                    {{ editingGroupUlid ? 'Renomear grupo' : 'Adicionar grupo' }}
                </h3>
                <Button
                    v-if="editingGroupUlid"
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="cancelGroupForm"
                >
                    <X class="size-4" /> Cancelar
                </Button>
            </div>
            <div class="grid gap-1.5 sm:max-w-xs">
                <Label for="class-group-label">Nome do grupo</Label>
                <Input
                    id="class-group-label"
                    v-model="groupForm.label"
                    type="text"
                    maxlength="40"
                    placeholder="T1"
                    required
                />
                <InputError :message="groupForm.errors.label" />
            </div>
            <Button
                type="submit"
                class="min-h-11"
                :disabled="groupForm.processing"
            >
                <Plus v-if="!editingGroupUlid" class="size-4" />{{
                    groupForm.processing
                        ? 'A guardar…'
                        : editingGroupUlid
                          ? 'Guardar nome'
                          : 'Adicionar grupo'
                }}
            </Button>
        </form>

        <!-- Distribuição inicial: sem datas, de propósito. -->
        <Dialog v-model:open="assignmentDialogOpen">
            <DialogContent class="max-h-[85vh] overflow-y-auto">
                <form @submit.prevent="submitAssignments">
                    <DialogHeader>
                        <DialogTitle>Distribuir alunos pelos grupos</DialogTitle>
                        <DialogDescription>
                            Marca o grupo de cada aluno. Não é preciso indicar
                            datas: a pertença começa no início do ano letivo,
                            ou no dia em que o aluno entrou na turma. Quem
                            ficar por atribuir fica «Sem grupo», o que é
                            perfeitamente válido.
                        </DialogDescription>
                    </DialogHeader>
                    <ul class="my-4 divide-y">
                        <li
                            v-for="student in ungrouped"
                            :key="student.ulid"
                            class="grid gap-2 py-2 sm:grid-cols-[1fr_10rem] sm:items-center"
                        >
                            <span class="min-w-0 truncate text-sm">
                                <span class="text-muted-foreground"
                                    >{{ student.class_number ?? '—' }}.</span
                                >
                                {{ student.name }}
                            </span>
                            <NativeSelect
                                v-model="draftAssignments[student.id]"
                                :aria-label="`Grupo de ${student.name}`"
                            >
                                <option :value="null">Sem grupo</option>
                                <option
                                    v-for="group in activeGroups"
                                    :key="group.ulid"
                                    :value="group.id"
                                >
                                    {{ group.label }}
                                </option>
                            </NativeSelect>
                        </li>
                    </ul>
                    <InputError :message="assignmentForm.errors.assignments" />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="assignmentDialogOpen = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            :disabled="assignmentForm.processing || assignmentCount === 0"
                        >
                            {{
                                assignmentForm.processing
                                    ? 'A guardar…'
                                    : `Guardar ${assignmentCount} atribuição(ões)`
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Mover: aqui a data é obrigatória, porque fecha uma janela e abre outra. -->
        <Dialog v-model:open="moveDialogOpen">
            <DialogContent>
                <form @submit.prevent="submitMove">
                    <DialogHeader>
                        <DialogTitle>
                            Mover {{ movingStudent?.name }}
                        </DialogTitle>
                        <DialogDescription>
                            De
                            <span class="font-medium text-foreground">{{
                                groupLabel(movingStudent?.class_group_id ?? null)
                            }}</span>
                            para o grupo que escolheres, a partir da data
                            indicada.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4 py-4">
                        <div class="grid gap-1.5">
                            <Label for="move-target-group">Novo grupo</Label>
                            <NativeSelect
                                id="move-target-group"
                                v-model="moveForm.class_group_id"
                            >
                                <option :value="null">Sem grupo</option>
                                <option
                                    v-for="group in activeGroups"
                                    :key="group.ulid"
                                    :value="group.id"
                                >
                                    {{ group.label }}
                                </option>
                            </NativeSelect>
                            <InputError :message="moveForm.errors.class_group_id" />
                        </div>
                        <div class="grid gap-1.5 rounded-md border border-dashed p-3">
                            <Label for="move-effective-from"
                                >Alteração válida a partir de</Label
                            >
                            <Input
                                id="move-effective-from"
                                v-model="moveForm.effective_from"
                                type="date"
                                required
                            />
                            <InputError :message="moveForm.errors.effective_from" />
                            <p class="text-xs text-muted-foreground">
                                As aulas até ao dia anterior mantêm a
                                configuração atual; a partir desta data passa a
                                vigorar a nova.
                            </p>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="moveDialogOpen = false"
                            >Cancelar</Button
                        >
                        <Button type="submit" :disabled="moveForm.processing">
                            {{ moveForm.processing ? 'A guardar…' : 'Mover aluno' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Permuta: dois alunos, UMA data, uma só operação. -->
        <Dialog v-model:open="swapDialogOpen">
            <DialogContent>
                <form @submit.prevent="submitSwap">
                    <DialogHeader>
                        <DialogTitle>Permutar alunos</DialogTitle>
                        <DialogDescription>
                            Os dois alunos trocam de grupo ao mesmo tempo, na
                            mesma data. Ou acontece a troca inteira, ou não
                            acontece nada.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4 py-4">
                        <div class="grid gap-1.5">
                            <Label for="swap-first">Aluno A</Label>
                            <NativeSelect
                                id="swap-first"
                                v-model="swapForm.first_enrollment_id"
                            >
                                <option
                                    v-for="student in groupedStudents"
                                    :key="student.ulid"
                                    :value="student.id"
                                >
                                    {{ student.name }} ·
                                    {{ groupLabel(student.class_group_id) }}
                                </option>
                            </NativeSelect>
                            <InputError
                                :message="swapForm.errors.first_enrollment_id"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="swap-second">Aluno B</Label>
                            <NativeSelect
                                id="swap-second"
                                v-model="swapForm.second_enrollment_id"
                            >
                                <option
                                    v-for="student in groupedStudents"
                                    :key="student.ulid"
                                    :value="student.id"
                                >
                                    {{ student.name }} ·
                                    {{ groupLabel(student.class_group_id) }}
                                </option>
                            </NativeSelect>
                            <InputError
                                :message="swapForm.errors.second_enrollment_id"
                            />
                        </div>
                        <p
                            v-if="swapFirst && swapSecond"
                            class="rounded-md bg-muted/40 p-3 text-xs text-muted-foreground"
                        >
                            {{ swapFirst.name }}:
                            {{ groupLabel(swapFirst.class_group_id) }} →
                            {{ groupLabel(swapSecond.class_group_id) }}<br />
                            {{ swapSecond.name }}:
                            {{ groupLabel(swapSecond.class_group_id) }} →
                            {{ groupLabel(swapFirst.class_group_id) }}
                        </p>
                        <div class="grid gap-1.5 rounded-md border border-dashed p-3">
                            <Label for="swap-effective-from"
                                >Alteração válida a partir de</Label
                            >
                            <Input
                                id="swap-effective-from"
                                v-model="swapForm.effective_from"
                                type="date"
                                required
                            />
                            <InputError :message="swapForm.errors.effective_from" />
                            <p class="text-xs text-muted-foreground">
                                As aulas até ao dia anterior mantêm a
                                configuração atual; a partir desta data passa a
                                vigorar a nova.
                            </p>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="swapDialogOpen = false"
                            >Cancelar</Button
                        >
                        <Button type="submit" :disabled="swapForm.processing">
                            {{ swapForm.processing ? 'A guardar…' : 'Permutar' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </section>
</template>
