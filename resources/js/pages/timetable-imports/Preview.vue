<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type ClassOption = {
    class_id: number;
    class_ulid: string;
    label: string;
    subject: string | null;
};

/** `new` · `exists` · `conflict` — decided server-side, and re-decided there. */
type RowStatus = 'new' | 'exists' | 'conflict';

type GroupRow = {
    day_of_week: number;
    weekday_label: string;
    starts_at: string;
    ends_at: string;
    subject_raw: string | null;
    room_raw: string | null;
    raw_text: string;
    status: RowStatus;
    include: boolean;
};

type Group = ClassOption & { rows: GroupRow[] };

type UnassociatedRow = {
    day_of_week: number;
    weekday_label: string;
    starts_at: string;
    ends_at: string;
    subject_raw: string | null;
    class_raw: string | null;
    room_raw: string | null;
    raw_text: string;
    reason: 'not_found' | 'ambiguous' | 'not_curricular';
    candidates: ClassOption[];
};

const props = defineProps<{
    groups: Group[];
    unassociated: UnassociatedRow[];
    classes: ClassOption[];
    academicYear: string | null;
    fileAcademicYear: string | null;
    yearMismatch: boolean;
    teacherName: string | null;
}>();

const statusLabels: Record<RowStatus, string> = {
    new: 'Novo',
    exists: 'Já existe',
    conflict: 'Conflito',
};

const reasonLabels: Record<UnassociatedRow['reason'], string> = {
    not_found: 'Turma não encontrada',
    ambiguous: 'Turma ambígua',
    not_curricular: 'Não é uma aula de turma',
};

const form = useForm({
    starts_on: '',
    ends_on: '',
    groups: props.groups.map((group) => ({
        class_id: group.class_id,
        rows: group.rows.map((row) => ({ ...row })),
    })),
    // One choice per unrecognised entry, all of them empty to begin with:
    // nothing here is ever guessed on the teacher's behalf.
    resolutions: props.unassociated.map((): number | null => null),
});

form.transform((data) => {
    const rows: Array<{
        class_id: number;
        day_of_week: number;
        starts_at: string;
        ends_at: string;
        include: boolean;
    }> = [];

    for (const group of data.groups) {
        for (const row of group.rows) {
            rows.push({
                class_id: group.class_id,
                day_of_week: row.day_of_week,
                starts_at: row.starts_at,
                ends_at: row.ends_at,
                include: row.include,
            });
        }
    }

    props.unassociated.forEach((entry, index) => {
        const classId = data.resolutions[index];

        if (classId === null) {
            return;
        }

        rows.push({
            class_id: classId,
            day_of_week: entry.day_of_week,
            starts_at: entry.starts_at,
            ends_at: entry.ends_at,
            include: true,
        });
    });

    return {
        starts_on: data.starts_on === '' ? null : data.starts_on,
        ends_on: data.ends_on === '' ? null : data.ends_on,
        unassociated_count: data.resolutions.filter((choice) => choice === null)
            .length,
        rows,
    };
});

// Row-level failures come back keyed by position — «rows.3.ends_at» — which is
// not part of this form's own declared shape, because the rows themselves are
// assembled by transform() at submit time. The first one is enough to say the
// import was refused; the preview above is where it gets corrected.
const rowError = computed<string | undefined>(() => {
    const errors = form.errors as unknown as Record<string, string | undefined>;

    return Object.entries(errors).find(
        ([key]) => key === 'rows' || key.startsWith('rows.'),
    )?.[1];
});

const selectedCount = computed(
    () =>
        form.groups.reduce(
            (total, group) =>
                total + group.rows.filter((row) => row.include).length,
            0,
        ) + form.resolutions.filter((choice) => choice !== null).length,
);

function optionsFor(entry: UnassociatedRow): ClassOption[] {
    return entry.candidates.length > 0 ? entry.candidates : props.classes;
}

function submit(): void {
    form.post('/timetable-imports/confirm');
}
</script>

<template>
    <Head title="Confirmar importação do horário" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <Heading
            title="Confirmar importação do horário"
            :description="
                teacherName
                    ? `Horário de ${teacherName}. Revê cada bloco antes de criar as aulas recorrentes.`
                    : 'Revê cada bloco antes de criar as aulas recorrentes.'
            "
        />

        <div
            v-if="yearMismatch"
            class="flex gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-4 text-sm"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0 text-amber-600" />
            <p>
                O ficheiro parece ser de
                <span class="font-medium">{{ fileAcademicYear }}</span>
                , mas está a importar para
                <span class="font-medium">{{ academicYear }}</span>
                . Pode continuar, se for mesmo isso que pretende — o ano letivo
                selecionado não é alterado.
            </p>
        </div>

        <form class="space-y-6" @submit.prevent="submit">
            <section
                class="space-y-3 rounded-lg border border-border bg-muted/20 p-4"
            >
                <div>
                    <h2 class="text-sm font-semibold">Vigência (opcional)</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Aplica-se a todas as aulas criadas nesta importação. Em
                        branco, cada aula fica limitada pelo ano letivo da sua
                        turma — tal como no formulário manual.
                    </p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="import-starts-on">Válido desde</Label>
                        <Input
                            id="import-starts-on"
                            v-model="form.starts_on"
                            type="date"
                        />
                        <InputError :message="form.errors.starts_on" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="import-ends-on">Válido até</Label>
                        <Input
                            id="import-ends-on"
                            v-model="form.ends_on"
                            type="date"
                        />
                        <InputError :message="form.errors.ends_on" />
                    </div>
                </div>
            </section>

            <section
                v-for="(group, groupIndex) in form.groups"
                :key="group.class_id"
                class="space-y-3 rounded-lg border border-border p-4"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h2 class="text-base font-semibold">
                        {{ props.groups[groupIndex].label }}
                    </h2>
                    <p
                        v-if="props.groups[groupIndex].subject"
                        class="text-sm text-muted-foreground"
                    >
                        {{ props.groups[groupIndex].subject }}
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="text-left text-xs text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="py-2 pr-3 font-medium">Incluir</th>
                                <th class="py-2 pr-3 font-medium">Dia</th>
                                <th class="py-2 pr-3 font-medium">Horas</th>
                                <th class="py-2 pr-3 font-medium">
                                    No ficheiro
                                </th>
                                <th class="py-2 font-medium">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="(row, rowIndex) in group.rows"
                                :key="`${row.day_of_week}-${row.starts_at}`"
                            >
                                <td class="py-2 pr-3">
                                    <input
                                        v-model="row.include"
                                        type="checkbox"
                                        :aria-label="`Incluir ${row.weekday_label} ${row.starts_at}`"
                                        :disabled="row.status !== 'new'"
                                    />
                                </td>
                                <td class="py-2 pr-3">
                                    {{ row.weekday_label }}
                                </td>
                                <td class="py-2 pr-3 whitespace-nowrap">
                                    {{ row.starts_at }}–{{ row.ends_at }}
                                </td>
                                <td class="py-2 pr-3 text-muted-foreground">
                                    {{ row.raw_text }}
                                </td>
                                <td class="py-2">
                                    <Badge
                                        :variant="
                                            row.status === 'new'
                                                ? 'secondary'
                                                : 'outline'
                                        "
                                    >
                                        {{ statusLabels[row.status] }}
                                    </Badge>
                                    <span
                                        v-if="row.status === 'conflict'"
                                        class="mt-1 block text-xs text-muted-foreground"
                                    >
                                        Sobrepõe-se a uma aula já marcada.
                                        Resolve no horário da turma.
                                    </span>
                                    <span
                                        v-else-if="
                                            rowIndex === 0 &&
                                            row.status === 'exists'
                                        "
                                        class="mt-1 block text-xs text-muted-foreground"
                                    >
                                        Já estava configurado — não é duplicado.
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <EmptyState
                v-if="form.groups.length === 0"
                title="Nenhum bloco do ficheiro foi associado a uma turma tua deste ano letivo."
            />

            <section
                v-if="unassociated.length > 0"
                class="space-y-3 rounded-lg border border-dashed border-border p-4"
            >
                <div>
                    <h2 class="text-base font-semibold">Não associadas</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Estes blocos não foram associados a nenhuma turma. Nada
                        é adivinhado: escolhe a turma quando souberes qual é, ou
                        deixa em branco para ignorar.
                    </p>
                </div>

                <ul class="divide-y divide-border">
                    <li
                        v-for="(entry, index) in unassociated"
                        :key="`${entry.day_of_week}-${entry.starts_at}-${entry.raw_text}`"
                        class="flex flex-wrap items-center justify-between gap-3 py-2.5 text-sm"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">
                                {{ entry.weekday_label }} ·
                                {{ entry.starts_at }}–{{ entry.ends_at }}
                            </p>
                            <p class="text-muted-foreground">
                                {{ entry.raw_text }} ·
                                {{ reasonLabels[entry.reason] }}
                            </p>
                        </div>
                        <select
                            v-if="entry.reason !== 'not_curricular'"
                            v-model="form.resolutions[index]"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                            :aria-label="`Turma para ${entry.raw_text}`"
                        >
                            <option :value="null">Ignorar</option>
                            <option
                                v-for="option in optionsFor(entry)"
                                :key="option.class_id"
                                :value="option.class_id"
                            >
                                {{ option.label
                                }}<template v-if="option.subject">
                                    · {{ option.subject }}</template
                                >
                            </option>
                        </select>
                    </li>
                </ul>
            </section>

            <InputError :message="rowError" />

            <div class="flex items-center gap-2">
                <Button
                    type="submit"
                    :disabled="form.processing || selectedCount === 0"
                >
                    {{
                        form.processing
                            ? 'A importar…'
                            : `Importar ${selectedCount} aula(s)`
                    }}
                </Button>
                <Button as-child variant="ghost">
                    <Link href="/timetable-imports/create">
                        <ArrowLeft class="size-4" /> Escolher outro ficheiro
                    </Link>
                </Button>
            </div>
        </form>
    </div>
</template>
