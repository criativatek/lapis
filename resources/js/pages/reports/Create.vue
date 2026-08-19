<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Info, Lock } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string; description?: string };

type ClassRow = { id: number; ulid: string; label: string; subject: string; academic_year: string };

type CatalogueRow = {
    key: string;
    heading: string;
    module: string | null;
    default_included: boolean;
    needs_teacher_input: boolean;
    may_name_students: boolean;
    available: boolean;
};

type ContextPayload = {
    periods: { id: number; label: string }[];
    enrollments: { id: number; class_number: number | null; name: string }[];
    interimAssessments: { id: number; name: string; reference_date: string }[];
};

type RecordKind = { value: string; label: string; group: string };

type AcademicYearRow = { id: number; label: string };

const props = defineProps<{
    type: string;
    availableTypes: Option[];
    classes: ClassRow[];
    catalogue: CatalogueRow[];
    tones: Option[];
    academicYears: AcademicYearRow[];
    recordKinds: RecordKind[];
}>();

const form = useForm({
    type: props.type,
    class_id: props.classes[0]?.id ?? null,
    academic_year_id: props.academicYears[0]?.id ?? null,
    academic_period_id: null as number | null,
    enrollment_id: null as number | null,
    interim_assessment_id: null as number | null,
    tone: props.tones[0]?.value ?? 'objective',
    title: '',
    sections: props.catalogue.filter((row) => row.default_included).map((row) => row.key),
    name_students: false,
    // Registos only.
    kinds: [] as string[],
    starts_on: '',
    ends_on: '',
    detailed: false,
});

const context = ref<ContextPayload>({ periods: [], enrollments: [], interimAssessments: [] });
const loadingContext = ref(false);

async function loadContext(classId: number | null) {
    if (classId === null) {
        context.value = { periods: [], enrollments: [], interimAssessments: [] };

        return;
    }

    const target = props.classes.find((row) => row.id === classId);

    if (!target) {
        return;
    }

    loadingContext.value = true;

    try {
        const response = await fetch(`/reports/contexto/${target.ulid}`, {
            headers: { Accept: 'application/json' },
        });
        context.value = (await response.json()) as ContextPayload;
    } finally {
        loadingContext.value = false;
    }
}

watch(
    () => form.class_id,
    (classId) => {
        form.academic_period_id = null;
        form.enrollment_id = null;
        form.interim_assessment_id = null;
        void loadContext(classId);
    },
    { immediate: true },
);

// Changing type reloads the page so the catalogue matches: which sections exist
// is a property of the type, and guessing it in the browser would let the form
// offer sections the server would then drop.
watch(
    () => form.type,
    (type) => {
        if (type !== props.type) {
            router.get('/reports/novo', { type }, { preserveScroll: true });
        }
    },
);

const isStudentReport = computed(() => form.type === 'student');
const isRecordsReport = computed(() => form.type === 'records');
// A school-wide report has no class: it aggregates across all of them (§24).
const isSchoolReport = computed(() => form.type === 'school');

// The detailed chronology is the section that lists records one by one, so the
// checkbox and the section have to agree — ticking one without the other would
// produce a report that promises a listing and prints none.
watch(
    () => form.detailed,
    (detailed) => {
        const key = 'records_timeline';
        const index = form.sections.indexOf(key);

        if (detailed && index === -1) {
            form.sections.push(key);
        } else if (!detailed && index !== -1) {
            form.sections.splice(index, 1);
        }
    },
);

function toggleKind(value: string) {
    const index = form.kinds.indexOf(value);

    if (index === -1) {
        form.kinds.push(value);
    } else {
        form.kinds.splice(index, 1);
    }
}

// A report built on a photograph reads the photograph and never reconstructs it
// from today's numbers, so the two choices are mutually exclusive.
watch(
    () => form.interim_assessment_id,
    (value) => {
        if (value !== null) {
            form.academic_period_id = null;
        }
    },
);

function toggleSection(key: string, available: boolean) {
    if (!available) {
        return;
    }

    const index = form.sections.indexOf(key);

    if (index === -1) {
        form.sections.push(key);
    } else {
        form.sections.splice(index, 1);
    }
}

const namesStudentsSections = computed(() =>
    props.catalogue.filter((row) => row.may_name_students && form.sections.includes(row.key)),
);

function submit() {
    form.post('/reports');
}
</script>

<template>
    <Head title="Novo relatório" />

    <div class="mx-auto w-full max-w-3xl space-y-8 p-4">
        <Link href="/reports" class="text-sm text-muted-foreground hover:underline">← Relatórios</Link>

        <Heading
            title="Novo relatório"
            description="Escolha o tipo, o contexto e as secções. O texto é gerado a seguir e fica editável."
        />

        <form class="space-y-8" @submit.prevent="submit">
            <!-- 1 ------------------------------------------------------ tipo -->
            <section class="space-y-3">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">1. Tipo</h2>

                <div class="grid gap-2 sm:grid-cols-2">
                    <label
                        v-for="option in availableTypes"
                        :key="option.value"
                        class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm"
                        :class="form.type === option.value ? 'border-primary bg-primary/5' : 'border-border'"
                    >
                        <input v-model="form.type" type="radio" :value="option.value" class="size-4" />
                        <span class="font-medium">{{ option.label }}</span>
                    </label>
                </div>
            </section>

            <!-- 2 --------------------------------------------------- contexto -->
            <section class="space-y-4">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">2. Contexto</h2>

                <div v-if="isSchoolReport" class="grid gap-2">
                    <Label for="year">Ano letivo</Label>
                    <select
                        id="year"
                        v-model.number="form.academic_year_id"
                        class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                    >
                        <option v-for="year in academicYears" :key="year.id" :value="year.id">{{ year.label }}</option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        Um relatório de escola é sempre agregado: não identifica alunos nem ordena turmas,
                        disciplinas ou docentes por desempenho.
                    </p>
                </div>

                <div v-else class="grid gap-2">
                    <Label for="class">Turma</Label>
                    <select
                        id="class"
                        v-model.number="form.class_id"
                        class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                    >
                        <option v-if="isRecordsReport" :value="null">Todas as minhas turmas</option>
                        <option v-for="schoolClass in classes" :key="schoolClass.id" :value="schoolClass.id">
                            {{ schoolClass.label }} · {{ schoolClass.subject }} · {{ schoolClass.academic_year }}
                        </option>
                    </select>
                </div>

                <div v-if="isStudentReport" class="grid gap-2">
                    <Label for="enrollment">Aluno</Label>
                    <select
                        id="enrollment"
                        v-model.number="form.enrollment_id"
                        class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                        :disabled="loadingContext"
                    >
                        <option :value="null">Selecione um aluno</option>
                        <option
                            v-for="enrollment in context.enrollments"
                            :key="enrollment.id"
                            :value="enrollment.id"
                        >
                            {{ enrollment.class_number ? `${enrollment.class_number}. ` : '' }}{{ enrollment.name }}
                        </option>
                    </select>
                </div>

                <div v-if="!isSchoolReport" class="grid gap-2">
                    <Label for="period">Período</Label>
                    <select
                        id="period"
                        v-model.number="form.academic_period_id"
                        class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                        :disabled="loadingContext || form.interim_assessment_id !== null"
                    >
                        <option :value="null">Ano letivo até ao momento</option>
                        <option v-for="period in context.periods" :key="period.id" :value="period.id">
                            {{ period.label }}
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        O relatório indica sempre o âmbito temporal. Se o resultado que responde por este momento
                        for o acumulado, a frase di-lo pelo nome — «Média Ponderada Acumulada».
                    </p>
                </div>

                <div v-if="context.interimAssessments.length > 0 && !isStudentReport" class="grid gap-2">
                    <Label for="interim">Ou partir de uma avaliação intercalar</Label>
                    <select
                        id="interim"
                        v-model.number="form.interim_assessment_id"
                        class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                    >
                        <option :value="null">Não — usar os dados atuais</option>
                        <option v-for="interim in context.interimAssessments" :key="interim.id" :value="interim.id">
                            {{ interim.name }} ({{ interim.reference_date }})
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        Um relatório sobre uma intercalar lê a fotografia guardada. Não é reconstruído a partir
                        dos números de hoje.
                    </p>
                </div>

                <!-- §21: an interval is what a logbook question is really
                     about. Offered instead of a period, not beside it. -->
                <template v-if="isRecordsReport">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="starts-on">De (opcional)</Label>
                            <Input id="starts-on" v-model="form.starts_on" type="date" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ends-on">Até (opcional)</Label>
                            <Input id="ends-on" v-model="form.ends_on" type="date" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label>Tipos de registo</Label>
                        <p class="text-xs text-muted-foreground">
                            Sem seleção, o relatório inclui todos os tipos.
                        </p>
                        <div class="grid gap-1.5 sm:grid-cols-2">
                            <label
                                v-for="kind in recordKinds"
                                :key="kind.value"
                                class="flex items-center gap-2 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    class="size-4"
                                    :checked="form.kinds.includes(kind.value)"
                                    @change="toggleKind(kind.value)"
                                />
                                <span>{{ kind.label }}</span>
                            </label>
                        </div>
                    </div>

                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.detailed" type="checkbox" class="mt-0.5 size-4" />
                        <span>
                            <span class="block font-medium">Incluir cronologia detalhada</span>
                            <span class="block text-xs text-muted-foreground">
                                Lista cada registo com data e descrição. Sem isto, o relatório apresenta apenas a
                                síntese.
                            </span>
                        </span>
                    </label>
                </template>

                <div class="grid gap-2">
                    <Label for="title">Título (opcional)</Label>
                    <Input id="title" v-model="form.title" placeholder="Gerado automaticamente se deixar em branco" />
                </div>
            </section>

            <!-- 3 --------------------------------------------------- secções -->
            <section class="space-y-3">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">3. Secções</h2>

                <ul class="divide-y divide-border overflow-hidden rounded-lg border border-border">
                    <li
                        v-for="row in catalogue"
                        :key="row.key"
                        class="flex items-start gap-3 px-4 py-3"
                        :class="row.available ? '' : 'bg-muted/30'"
                    >
                        <input
                            :id="`section-${row.key}`"
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0"
                            :checked="form.sections.includes(row.key)"
                            :disabled="!row.available"
                            @change="toggleSection(row.key, row.available)"
                        />
                        <label :for="`section-${row.key}`" class="min-w-0 flex-1 text-sm">
                            <span class="flex flex-wrap items-center gap-2">
                                <span :class="row.available ? 'font-medium' : 'font-medium text-muted-foreground'">
                                    {{ row.heading }}
                                </span>
                                <span
                                    v-if="!row.available"
                                    class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground"
                                >
                                    <Lock class="size-3" />
                                    Plano Pro
                                </span>
                                <span
                                    v-if="row.may_name_students"
                                    class="rounded-full bg-amber-500/10 px-2 py-0.5 text-[11px] text-amber-700 dark:text-amber-400"
                                >
                                    Pode identificar alunos
                                </span>
                            </span>
                            <span v-if="row.needs_teacher_input" class="mt-0.5 block text-xs text-muted-foreground">
                                Depende do que indicar na caracterização — não é inferida dos dados.
                            </span>
                        </label>
                    </li>
                </ul>

                <div
                    v-if="namesStudentsSections.length > 0"
                    class="flex items-start gap-3 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm"
                >
                    <Info class="mt-0.5 size-4 shrink-0 text-amber-600" />
                    <label class="flex-1">
                        <span class="flex items-center gap-2 font-medium">
                            <input v-model="form.name_students" type="checkbox" class="size-4" />
                            Permitir identificar alunos pelo nome
                        </span>
                        <span class="mt-1 block text-xs text-muted-foreground">
                            Sem isto, as secções selecionadas descrevem situações sem identificar quem. Um relatório
                            de turma é agregado por omissão.
                        </span>
                    </label>
                </div>
            </section>

            <!-- 4 ---------------------------------------------------- registo -->
            <section v-if="tones.length > 1" class="space-y-3">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">4. Registo</h2>

                <div class="grid gap-2">
                    <label
                        v-for="tone in tones"
                        :key="tone.value"
                        class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm"
                        :class="form.tone === tone.value ? 'border-primary bg-primary/5' : 'border-border'"
                    >
                        <input v-model="form.tone" type="radio" :value="tone.value" class="mt-0.5 size-4" />
                        <span>
                            <span class="block font-medium">{{ tone.label }}</span>
                            <span class="block text-xs text-muted-foreground">{{ tone.description }}</span>
                        </span>
                    </label>
                </div>
            </section>

            <div class="flex items-center gap-3 border-t border-border pt-6">
                <Button
                    type="submit"
                    :disabled="
                        form.processing ||
                        (isSchoolReport ? form.academic_year_id === null : !isRecordsReport && form.class_id === null)
                    "
                >
                    Gerar rascunho
                </Button>
                <Button variant="ghost" as-child>
                    <Link href="/reports">Cancelar</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
