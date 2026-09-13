<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CheckCircle2 } from '@lucide/vue';
import { computed, reactive } from 'vue';
import AlertError from '@/components/AlertError.vue';
import InputError from '@/components/InputError.vue';
import StudentAvatar from '@/components/StudentAvatar.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

export type AttendanceStudent = {
    student_ulid: string;
    enrollment_ulid: string | null;
    class_number: number | null;
    name: string;
    photo_url: string | null;
    status: 'present' | 'absent' | null;
};

export type LessonAttendance = {
    recorded: boolean;
    recorded_at: string | null;
    can_edit: boolean;
    excluded_without_left_on: number;
    students: AttendanceStudent[];
    counts: { present: number; absent: number };
    roster_error: string | null;
};

const props = defineProps<{
    lessonUlid: string;
    attendance: LessonAttendance;
    classGroupLabel: string | null;
    lessonTaught: boolean;
    /** Controlado pela página: entra no payload do sumário e do "marcar lecionada". */
    absent: string[];
}>();

const emit = defineEmits<{ 'update:absent': [string[]]; record: [] }>();

// Antes da consolidação só existem rascunhos de FALTA — nunca linhas
// "present". O estado local começa a partir do que o servidor já sabia
// (`status === 'absent'`) e só é reescrito por aqui em diante.
const draftAbsent = computed({
    get: () => props.absent,
    set: (value: string[]) => emit('update:absent', value),
});

function isDraftAbsent(studentUlid: string): boolean {
    return draftAbsent.value.includes(studentUlid);
}

function toggleDraft(studentUlid: string): void {
    if (isDraftAbsent(studentUlid)) {
        draftAbsent.value = draftAbsent.value.filter((ulid) => ulid !== studentUlid);
    } else {
        draftAbsent.value = [...draftAbsent.value, studentUlid];
    }
}

// Correção pós-consolidação: cada linha já existe no servidor, por isso cada
// pedido é um PATCH isolado — nunca um lote — com o próprio botão desativado
// enquanto está em curso, para não deixar cliques duplos em trânsito.
const correcting = reactive<Record<string, boolean>>({});
const correctionErrors = reactive<Record<string, string>>({});

function correctedStatus(student: AttendanceStudent): 'present' | 'absent' {
    return student.status === 'absent' ? 'absent' : 'present';
}

function toggleCorrection(student: AttendanceStudent): void {
    if (correcting[student.student_ulid]) {
        return;
    }

    const nextStatus = correctedStatus(student) === 'absent' ? 'present' : 'absent';
    correcting[student.student_ulid] = true;
    delete correctionErrors[student.student_ulid];

    router.patch(
        `/lessons/${props.lessonUlid}/attendance/${student.student_ulid}`,
        { status: nextStatus },
        {
            preserveScroll: true,
            onSuccess: () => {
                student.status = nextStatus;
            },
            onError: (errors) => {
                correctionErrors[student.student_ulid] =
                    (errors.status as string | undefined) ?? 'Não foi possível corrigir esta assiduidade.';
            },
            onFinish: () => {
                correcting[student.student_ulid] = false;
            },
        },
    );
}

const draftAbsentCount = computed(() => draftAbsent.value.length);

const consolidatedNote = computed(() => {
    if (!props.attendance.recorded) {
        return null;
    }

    const { present, absent } = props.attendance.counts;

    return `Registada: ${present} ${present === 1 ? 'presença' : 'presenças'} · ${absent} ${absent === 1 ? 'falta' : 'faltas'}. Podes corrigir.`;
});
</script>

<template>
    <div class="space-y-3 rounded-xl border bg-card p-4 sm:p-5">
        <div class="space-y-1">
            <h2 class="text-base font-semibold">Assiduidade</h2>
            <p v-if="!attendance.recorded && !lessonTaught" class="text-sm text-muted-foreground">
                Assinala só quem faltou. Ao marcar a aula como lecionada, os restantes ficam registados como presentes.
            </p>
            <p v-else-if="attendance.recorded" class="text-sm text-muted-foreground">{{ consolidatedNote }}</p>
            <p v-else class="text-sm text-muted-foreground">Assiduidade não registada.</p>
        </div>

        <p v-if="classGroupLabel" class="text-xs text-muted-foreground">
            Só aparecem os alunos de {{ classGroupLabel }} nesta data.
        </p>

        <AlertError
            v-if="attendance.roster_error"
            :errors="[attendance.roster_error]"
            title="Não foi possível determinar os alunos desta aula."
        />

        <template v-else>
            <p v-if="attendance.excluded_without_left_on > 0" class="text-xs text-muted-foreground">
                {{ attendance.excluded_without_left_on }} inscrição(ões) terminada(s) sem data de saída não aparecem
                nesta lista.
            </p>

            <p v-if="attendance.students.length === 0" class="text-sm text-muted-foreground">
                Não há alunos elegíveis para esta aula.
            </p>

            <ul v-else class="flex flex-col gap-2" data-testid="attendance-rows">
                <li
                    v-for="student in attendance.students"
                    :key="student.student_ulid"
                    class="flex items-center gap-3 rounded-lg border p-2"
                    :class="
                        (attendance.recorded ? student.status === 'absent' : isDraftAbsent(student.student_ulid))
                            ? 'border-destructive/40 bg-destructive/5'
                            : 'border-border'
                    "
                >
                    <StudentAvatar :photo-url="student.photo_url" :student-name="student.name" />

                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-2 text-sm">
                            <span v-if="student.class_number !== null" class="tabular-nums text-muted-foreground"
                                >{{ student.class_number }}.</span
                            >
                            <span class="truncate font-medium">{{ student.name }}</span>
                        </p>
                        <p
                            v-if="attendance.recorded ? student.status === 'absent' : isDraftAbsent(student.student_ulid)"
                            class="text-xs font-medium text-destructive"
                        >
                            Falta
                        </p>
                        <InputError
                            v-if="correctionErrors[student.student_ulid]"
                            :message="correctionErrors[student.student_ulid]"
                        />
                    </div>

                    <!-- Antes da consolidação: rascunho local. Depois: correção
                         no servidor, um PATCH por linha. -->
                    <button
                        v-if="!attendance.recorded"
                        type="button"
                        :disabled="!attendance.can_edit"
                        :aria-pressed="isDraftAbsent(student.student_ulid)"
                        class="flex min-h-11 min-w-11 items-center justify-center gap-1.5 rounded-lg border px-3 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                        :class="
                            isDraftAbsent(student.student_ulid)
                                ? 'border-destructive bg-destructive text-destructive-foreground'
                                : 'border-input bg-background hover:bg-accent'
                        "
                        @click="toggleDraft(student.student_ulid)"
                    >
                        Falta
                    </button>
                    <button
                        v-else
                        type="button"
                        :disabled="!attendance.can_edit || correcting[student.student_ulid]"
                        :aria-pressed="correctedStatus(student) === 'absent'"
                        class="flex min-h-11 min-w-24 items-center justify-center gap-1.5 rounded-lg border px-3 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                        :class="
                            correctedStatus(student) === 'absent'
                                ? 'border-destructive bg-destructive text-destructive-foreground'
                                : 'border-emerald-600/40 bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
                        "
                        @click="toggleCorrection(student)"
                    >
                        <Spinner v-if="correcting[student.student_ulid]" class="size-4" />
                        <CheckCircle2 v-else-if="correctedStatus(student) === 'present'" class="size-4" />
                        {{ correctedStatus(student) === 'absent' ? 'Falta' : 'Presente' }}
                    </button>
                </li>
            </ul>

            <div
                v-if="!attendance.recorded && attendance.students.length > 0"
                class="flex flex-wrap items-center gap-2 border-t pt-3 text-sm"
            >
                <Badge variant="outline" class="tabular-nums">
                    {{ draftAbsentCount === 1 ? "1 falta assinalada" : `${draftAbsentCount} faltas assinaladas` }}
                </Badge>
            </div>

            <div
                v-if="lessonTaught && !attendance.recorded && attendance.can_edit && attendance.students.length > 0"
                class="border-t pt-3"
            >
                <p class="mb-2 text-xs text-muted-foreground">
                    Os alunos sem falta assinalada ficam registados como presentes.
                </p>
                <Button type="button" variant="secondary" class="min-h-11 w-full sm:w-auto" @click="emit('record')">
                    Registar assiduidade
                </Button>
            </div>
        </template>
    </div>
</template>
