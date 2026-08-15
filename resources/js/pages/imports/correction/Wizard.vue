<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, CircleHelp, MinusCircle } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';

type Issue = { code: string; severity: string; message: string; context: Record<string, unknown> };
type StudentRow = {
    source_key: string;
    card_number: string | null;
    display_name: string | null;
    source_score: string | null;
    source_answered: number | null;
    participated: boolean;
    enrollment_id: number | null;
    status: 'matched' | 'ambiguous' | 'unmatched' | 'ignored';
    reason: string | null;
    candidates: { id: number; label: string }[];
};
type ItemRow = {
    source_key: string;
    sequence: number;
    code: string | null;
    label: string | null;
    question_text: string | null;
    answer_key: string | null;
    source_points: string | null;
    points: string | null;
    points_locked: boolean;
    instrument_item_id: number | null;
    domains: { domain_id: number; allocation_percent: string }[];
};
type EligibleInstrument = {
    id: number;
    title: string;
    applied_on: string;
    status_label: string;
    items: { id: number; code: string; label: string | null; group: string | null; points_possible: string }[];
};
type Conflict = { enrollment_id: number; instrument_item_id: number; current: string; incoming: string };

const props = defineProps<{
    correctionImport: {
        ulid: string;
        status: string;
        statusLabel: string;
        originalFilename: string | null;
        class: { id: number; label: string };
    };
    preview: {
        source: string;
        source_label: string;
        suggested_title: string | null;
        mode: string;
        instrument_id: number | null;
        instrument_attributes: Record<string, unknown>;
        items: ItemRow[];
        students: StudentRow[];
        counts: Record<string, number>;
        issues: Issue[];
        can_confirm: boolean;
        eligible_instruments: EligibleInstrument[];
    };
    conflicts: Conflict[];
    duplicateOfEarlierImport: boolean;
    catalogue: {
        instrument_types: { id: number; code: string; name: string }[];
        domains: { id: number; name: string }[];
    };
}>();

const step = ref(2);

// Everything the teacher decides. Sent whole on every save so the server sees
// one coherent set of decisions rather than a trickle of partial ones.
const form = useForm({
    mode: props.preview.mode,
    instrument_id: props.preview.instrument_id,
    students: Object.fromEntries(
        props.preview.students
            .filter((student) => student.status === 'matched' || student.status === 'ignored')
            .map((student) => [student.source_key, student.enrollment_id]),
    ) as Record<string, number | null>,
    items: Object.fromEntries(
        props.preview.items
            .filter((item) => item.instrument_item_id !== null)
            .map((item) => [item.source_key, item.instrument_item_id]),
    ) as Record<string, number | null>,
    points: Object.fromEntries(props.preview.items.map((item) => [item.source_key, item.points ?? ''])) as Record<string, string>,
    domains: Object.fromEntries(props.preview.items.map((item) => [item.source_key, item.domains])) as Record<
        string,
        { domain_id: number; allocation_percent: string }[]
    >,
    conflicts: {} as Record<string, string>,
    instrument: {
        title: (props.preview.instrument_attributes.title as string) ?? props.preview.suggested_title ?? '',
        instrument_type_id: (props.preview.instrument_attributes.instrument_type_id as number) ?? null,
        // Deliberately empty: the Plickers export's «(25/26)» is a school year,
        // not a date, and the date decides which students the instrument even
        // applies to. The teacher types it.
        applied_on: (props.preview.instrument_attributes.applied_on as string) ?? '',
        academic_period_id: (props.preview.instrument_attributes.academic_period_id as number) ?? null,
        purpose: (props.preview.instrument_attributes.purpose as string) ?? 'formative',
        counts_toward_classification: (props.preview.instrument_attributes.counts_toward_classification as boolean) ?? true,
        total_points: (props.preview.instrument_attributes.total_points as number) ?? null,
    },
});

const errors = computed(() => props.preview.issues.filter((issue) => issue.severity === 'error'));
const warnings = computed(() => props.preview.issues.filter((issue) => issue.severity === 'warning'));
const infos = computed(() => props.preview.issues.filter((issue) => issue.severity === 'info'));

const creating = computed(() => form.mode === 'create_new');
const chosenInstrument = computed(() => props.preview.eligible_instruments.find((row) => row.id === form.instrument_id));

const uniformPoints = ref('');

/** Applies one cotação to every question at once — a suggestion, never a default (§14). */
function applyUniformPoints(): void {
    if (uniformPoints.value === '') {
        return;
    }

    for (const item of props.preview.items) {
        if (!item.points_locked) {
            form.points[item.source_key] = uniformPoints.value;
        }
    }
}

/** One domain for every question, at 100%. The common case, still chosen explicitly (§16). */
const uniformDomain = ref<number | null>(null);

function applyUniformDomain(): void {
    if (uniformDomain.value === null) {
        return;
    }

    for (const item of props.preview.items) {
        form.domains[item.source_key] = [{ domain_id: uniformDomain.value, allocation_percent: '100' }];
    }
}

function save(): void {
    form.patch(`/imports/correction/${props.correctionImport.ulid}`, { preserveScroll: true });
}

function confirmImport(): void {
    router.post(`/imports/correction/${props.correctionImport.ulid}/confirm`);
}

function cancelImport(): void {
    router.delete(`/imports/correction/${props.correctionImport.ulid}`);
}

const statusIcon = {
    matched: CircleCheck,
    ambiguous: CircleHelp,
    unmatched: CircleAlert,
    ignored: MinusCircle,
};

const statusLabel = {
    matched: 'Correspondência confirmada',
    ambiguous: 'Confirmar',
    unmatched: 'Não encontrado',
    ignored: 'Ignorado',
};
</script>

<template>
    <Head title="Importar grelha" />

    <div class="mx-auto w-full max-w-5xl space-y-6 p-4">
        <div>
            <Heading
                title="Importar grelha"
                :description="`${preview.source_label} · ${correctionImport.class.label}${correctionImport.originalFilename ? ' · ' + correctionImport.originalFilename : ''}`"
            />
            <Link href="/assessments" class="text-sm text-muted-foreground hover:underline">← Voltar a Avaliações</Link>
        </div>

        <!-- Steps. Text as well as position, so the current step is not signalled by colour alone. -->
        <ol class="flex flex-wrap gap-2 text-sm">
            <li v-for="(label, index) in ['Origem e ficheiro', 'Instrumento e estrutura', 'Alunos e resultados', 'Revisão e importação']" :key="label">
                <button
                    type="button"
                    class="rounded-md border px-3 py-1.5"
                    :class="step === index + 1 ? 'border-primary bg-primary text-primary-foreground' : 'border-border'"
                    :aria-current="step === index + 1 ? 'step' : undefined"
                    :disabled="index === 0"
                    @click="step = index + 1"
                >
                    {{ index + 1 }}. {{ label }}<span v-if="step === index + 1" class="sr-only"> (passo atual)</span>
                </button>
            </li>
        </ol>

        <p class="rounded-md border border-border bg-muted/30 px-4 py-3 text-sm">
            Encontrámos <strong>{{ preview.counts.students_in_file }}</strong> alunos,
            <strong>{{ preview.counts.questions }}</strong> perguntas e
            <strong>{{ preview.counts.responses }}</strong> respostas.
            <span v-if="preview.counts.non_participants > 0">
                {{ preview.counts.non_participants }} não participaram nesta aplicação.
            </span>
        </p>

        <p v-if="duplicateOfEarlierImport" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Este ficheiro parece já ter sido utilizado numa importação anterior.
        </p>

        <!-- PASSO 2 -->
        <section v-if="step === 2" class="space-y-5">
            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">O que fazer com estes resultados</legend>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.mode" type="radio" value="create_new" /> Criar um novo instrumento
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.mode" type="radio" value="associate_existing" /> Associar a um instrumento existente
                </label>
            </fieldset>

            <div v-if="creating" class="space-y-4 rounded-md border border-border p-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <label for="i-title" class="text-sm font-medium">Título</label>
                        <input id="i-title" v-model="form.instrument.title" type="text" class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm" />
                    </div>
                    <div class="space-y-1.5">
                        <label for="i-date" class="text-sm font-medium">Data de aplicação</label>
                        <input id="i-date" v-model="form.instrument.applied_on" type="date" class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm" />
                        <p class="text-xs text-muted-foreground">O ficheiro não indica a data. Confirme-a: é ela que decide a que alunos o instrumento se aplica.</p>
                    </div>
                    <div class="space-y-1.5">
                        <label for="i-type" class="text-sm font-medium">Tipo</label>
                        <select id="i-type" v-model="form.instrument.instrument_type_id" class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm">
                            <option :value="null">—</option>
                            <option v-for="type in catalogue.instrument_types" :key="type.id" :value="type.id">{{ type.name }}</option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <label for="i-purpose" class="text-sm font-medium">Finalidade</label>
                        <select id="i-purpose" v-model="form.instrument.purpose" class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm">
                            <option value="diagnostic">Diagnóstica</option>
                            <option value="formative">Formativa</option>
                            <option value="summative">Sumativa</option>
                        </select>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.instrument.counts_toward_classification" type="checkbox" /> Conta para a classificação
                </label>
            </div>

            <div v-else class="space-y-3 rounded-md border border-border p-4">
                <label for="i-existing" class="text-sm font-medium">Instrumento</label>
                <select id="i-existing" v-model="form.instrument_id" class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm">
                    <option :value="null">—</option>
                    <option v-for="row in preview.eligible_instruments" :key="row.id" :value="row.id">
                        {{ row.title }} · {{ row.applied_on }} · {{ row.status_label }}
                    </option>
                </select>
                <p class="text-xs text-muted-foreground">
                    Só aparecem instrumentos desta turma onde ainda é possível registar classificações. Uma correção
                    já concluída tem de ser reaberta primeiro, na própria grelha.
                </p>
            </div>

            <!-- Cotações -->
            <div class="space-y-3 rounded-md border border-border p-4">
                <h2 class="text-sm font-medium">Cotação das perguntas</h2>
                <p class="text-xs text-muted-foreground">
                    O ficheiro {{ preview.source_label }} não fornece uma cotação individual para estas perguntas.
                    Defina-a antes de importar — nada é assumido por omissão.
                </p>
                <div v-if="creating" class="flex flex-wrap items-end gap-2">
                    <div class="space-y-1.5">
                        <label for="uniform-points" class="text-xs font-medium">A mesma para todas</label>
                        <input id="uniform-points" v-model="uniformPoints" type="number" min="0" step="0.01" class="h-9 w-28 rounded-md border border-input bg-transparent px-2 text-sm" />
                    </div>
                    <button type="button" class="h-9 rounded-md border border-border px-3 text-sm" @click="applyUniformPoints">Aplicar a todas</button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left">
                            <tr>
                                <th class="py-1 font-medium">Código</th>
                                <th class="py-1 font-medium">Pergunta</th>
                                <th class="py-1 font-medium">Resposta certa</th>
                                <th class="py-1 font-medium">Cotação</th>
                                <th v-if="!creating" class="py-1 font-medium">Pergunta do instrumento</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="item in preview.items" :key="item.source_key">
                                <td class="py-1.5 whitespace-nowrap">{{ item.code }}</td>
                                <td class="py-1.5">{{ item.label ?? '—' }}</td>
                                <td class="py-1.5">{{ item.answer_key ?? '—' }}</td>
                                <td class="py-1.5">
                                    <input
                                        v-model="form.points[item.source_key]"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        :disabled="item.points_locked"
                                        :aria-label="`Cotação da pergunta ${item.code}`"
                                        class="h-8 w-24 rounded-md border border-input bg-transparent px-2 text-sm disabled:opacity-60"
                                    />
                                </td>
                                <td v-if="!creating" class="py-1.5">
                                    <select
                                        v-model="form.items[item.source_key]"
                                        :aria-label="`Pergunta do instrumento para ${item.code}`"
                                        class="h-8 rounded-md border border-input bg-transparent px-2 text-sm"
                                    >
                                        <option :value="null">—</option>
                                        <option v-for="target in chosenInstrument?.items ?? []" :key="target.id" :value="target.id">
                                            {{ target.group ? target.group + ' · ' : '' }}{{ target.code }}
                                        </option>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Domínios -->
            <div v-if="creating" class="space-y-3 rounded-md border border-border p-4">
                <h2 class="text-sm font-medium">Domínios</h2>
                <p class="text-xs text-muted-foreground">
                    O ficheiro não indica domínios curriculares e o LÁPIS não os infere pelo texto das perguntas.
                </p>
                <div class="flex flex-wrap items-end gap-2">
                    <div class="space-y-1.5">
                        <label for="uniform-domain" class="text-xs font-medium">Um domínio para todas</label>
                        <select id="uniform-domain" v-model="uniformDomain" class="h-9 rounded-md border border-input bg-transparent px-2 text-sm">
                            <option :value="null">—</option>
                            <option v-for="domain in catalogue.domains" :key="domain.id" :value="domain.id">{{ domain.name }}</option>
                        </select>
                    </div>
                    <button type="button" class="h-9 rounded-md border border-border px-3 text-sm" @click="applyUniformDomain">Aplicar a todas</button>
                </div>
            </div>

            <button type="button" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground" @click="save(); step = 3">
                Guardar e continuar
            </button>
        </section>

        <!-- PASSO 3 -->
        <section v-if="step === 3" class="space-y-4">
            <div class="overflow-x-auto rounded-md border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-left">
                        <tr>
                            <th class="px-3 py-2 font-medium">Aluno na origem</th>
                            <th class="px-3 py-2 font-medium">Aluno no LÁPIS</th>
                            <th class="px-3 py-2 font-medium">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="student in preview.students" :key="student.source_key">
                            <td class="px-3 py-2">
                                <span class="font-medium">{{ student.display_name ?? '—' }}</span>
                                <span v-if="student.card_number" class="text-muted-foreground"> · cartão {{ student.card_number }}</span>
                                <!-- A fact about the file, never a judgement about the student. -->
                                <span v-if="!student.participated" class="block text-xs text-amber-700">
                                    Não participou nesta aplicação
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <select
                                    v-model="form.students[student.source_key]"
                                    :aria-label="`Aluno do LÁPIS para ${student.display_name ?? student.source_key}`"
                                    class="h-8 w-full rounded-md border border-input bg-transparent px-2 text-sm"
                                >
                                    <option :value="undefined">— por decidir —</option>
                                    <option :value="null">Ignorar esta linha</option>
                                    <option v-for="candidate in student.candidates" :key="candidate.id" :value="candidate.id">
                                        {{ candidate.label }}
                                    </option>
                                </select>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <component :is="statusIcon[student.status]" class="inline size-3.5" aria-hidden="true" />
                                {{ statusLabel[student.status] }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-muted-foreground">
                Alunos da turma que não constem do ficheiro ficam por avaliar — não recebem zero nem falta. Uma
                resposta em branco também não é uma resposta errada.
            </p>

            <button type="button" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground" @click="save(); step = 4">
                Guardar e continuar
            </button>
        </section>

        <!-- PASSO 4 -->
        <section v-if="step === 4" class="space-y-4">
            <dl class="grid gap-2 rounded-md border border-border p-4 text-sm sm:grid-cols-2">
                <div><dt class="inline text-muted-foreground">Origem: </dt><dd class="inline">{{ preview.source_label }}</dd></div>
                <div><dt class="inline text-muted-foreground">Turma: </dt><dd class="inline">{{ correctionImport.class.label }}</dd></div>
                <div><dt class="inline text-muted-foreground">Perguntas: </dt><dd class="inline">{{ preview.counts.questions }}</dd></div>
                <div><dt class="inline text-muted-foreground">Alunos no ficheiro: </dt><dd class="inline">{{ preview.counts.students_in_file }}</dd></div>
                <div><dt class="inline text-muted-foreground">Correspondências: </dt><dd class="inline">{{ preview.counts.matched }}</dd></div>
                <div><dt class="inline text-muted-foreground">Ignorados: </dt><dd class="inline">{{ preview.counts.ignored }}</dd></div>
                <div><dt class="inline text-muted-foreground">Não participantes: </dt><dd class="inline">{{ preview.counts.non_participants }}</dd></div>
                <div><dt class="inline text-muted-foreground">Respostas classificáveis: </dt><dd class="inline">{{ preview.counts.responses_judged }}</dd></div>
                <div><dt class="inline text-muted-foreground">Por resolver: </dt><dd class="inline">{{ preview.counts.responses_unresolved }}</dd></div>
                <div><dt class="inline text-muted-foreground">Conflitos: </dt><dd class="inline">{{ conflicts.length }}</dd></div>
            </dl>

            <div v-if="errors.length" class="space-y-1 rounded-md border border-destructive/40 bg-destructive/5 p-4">
                <p class="text-sm font-medium">Por resolver antes de importar</p>
                <ul class="list-inside list-disc text-sm">
                    <li v-for="issue in errors" :key="issue.code + issue.message">{{ issue.message }}</li>
                </ul>
            </div>

            <div v-if="warnings.length" class="space-y-1 rounded-md border border-amber-300 bg-amber-50 p-4 text-amber-900">
                <p class="text-sm font-medium">Avisos</p>
                <ul class="list-inside list-disc text-sm">
                    <li v-for="issue in warnings" :key="issue.code + issue.message">{{ issue.message }}</li>
                </ul>
            </div>

            <ul v-if="infos.length" class="list-inside list-disc text-xs text-muted-foreground">
                <li v-for="issue in infos" :key="issue.code + issue.message">{{ issue.message }}</li>
            </ul>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50"
                    :disabled="!preview.can_confirm"
                    @click="confirmImport"
                >
                    Confirmar importação
                </button>
                <span v-if="!preview.can_confirm" class="text-xs text-muted-foreground">
                    Ainda há pontos por resolver acima.
                </span>
                <button type="button" class="rounded-md border border-border px-4 py-2 text-sm" @click="cancelImport">
                    Cancelar importação
                </button>
            </div>

            <p class="text-xs text-muted-foreground">
                Depois de importar, o instrumento fica <strong>em correção</strong>. Reveja a correção antes de a
                concluir — concluir continua a ser uma decisão sua.
            </p>
        </section>
    </div>
</template>
