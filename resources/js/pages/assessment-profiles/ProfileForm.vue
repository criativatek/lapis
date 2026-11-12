<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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

type Option = {
    id: number;
    label: string;
    system?: boolean;
    kind: string;
    min_value: number;
    max_value: number;
    ulid?: string;
    organization_default?: boolean;
    levels?: {
        code: string;
        label: string;
        from_percent?: number;
        inovar_code?: string | null;
        is_negative?: boolean;
    }[];
};

type DomainRow = { name: string; weight: number };

type ProfileData = {
    name: string;
    academic_year_id: number | null;
    subject_id: number | null;
    grade_levels: string[];
    description: string | null;
    scale_id: number | null;
    domains: DomainRow[];
};

const props = withDefaults(
    defineProps<{
        academicYears: Option[];
        subjects: Option[];
        scales: Option[];
        initial?: ProfileData;
        submitUrl: string;
        method: 'post' | 'put';
        // True on Create (only the owner ever reaches that route) and passed
        // explicitly on Edit, where a member may still be VIEWING a profile
        // they did not write, to see the domains and weights they grade by.
        canManage?: boolean;
        /** A escala com que um perfil NOVO abre («Escala 1 a 5»); ignorada quando há `initial`. */
        defaultScaleId?: number | null;
    }>(),
    { canManage: true, defaultScaleId: null },
);

const form = useForm<ProfileData>(
    props.initial ?? {
        name: '',
        academic_year_id: null,
        subject_id: null,
        grade_levels: [],
        description: null,
        scale_id: props.defaultScaleId ?? null,
        domains: [
            { name: '', weight: 0 },
            { name: '', weight: 0 },
        ],
    },
);

const gradeLevelInput = ref('');

function addGradeLevel(): void {
    const value = gradeLevelInput.value.trim();

    if (value === '' || form.grade_levels.includes(value)) {
        gradeLevelInput.value = '';

        return;
    }

    form.grade_levels.push(value);
    gradeLevelInput.value = '';
}

function removeGradeLevel(index: number): void {
    form.grade_levels.splice(index, 1);
}

const totalWeight = computed(() =>
    form.domains.reduce((sum, domain) => sum + (Number(domain.weight) || 0), 0),
);

const weightsOk = computed(() => Math.abs(totalWeight.value - 100) < 0.0001);
const scaleDialogOpen = ref(false);
const scaleForm = useForm<{
    name: string;
    min_value: number | null;
    max_value: number | null;
}>({
    name: '',
    min_value: null,
    max_value: null,
});

function openScaleDialog(): void {
    scaleForm.reset();
    scaleForm.clearErrors();
    scaleDialogOpen.value = true;
}

function submitScale(): void {
    scaleForm.post('/scales', {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            const newScale = props.scales.find(
                (scale) => !scale.system && scale.label === scaleForm.name,
            );

            if (newScale) {
                form.scale_id = newScale.id;
            }

            scaleDialogOpen.value = false;
            scaleForm.reset();
        },
    });
}

// ---------------------------------------------------- personalizar escala
//
// A ESCALA DA ESCOLA, POR PERFIL. Por defeito nada muda: quem não carregar
// aqui fica com «Fraco, Insuficiente, Suficiente, Bom, Muito Bom» e os cortes
// 0, 20, 50, 70, 90. O professor pode ter de 2 a 10 níveis, cada um com nome,
// «a partir de X %» (inteiro) e o código que a escola definiu no Inovar, e
// diz a partir de que nível é positiva. Guardar cria uma escala da
// organização derivada da do sistema (ou atualiza-a, enquanto não congelada) e
// o perfil passa a apontar-lhe. O servidor é a autoridade: o que se valida
// aqui serve só para o feedback ser imediato.

type MentionLevel = { label: string; from_percent: number | string; inovar_code: string };

const MIN_LEVELS = 2;
const MAX_LEVELS = 10;
const INOVAR_CODE_MAX_LENGTH = 8;

/** O que o Inovar costuma chamar a cada nível de origem — só sugestões. */
const defaultInovarCodes: Record<string, string> = {
    fraco: 'F',
    insuficiente: 'I',
    suficiente: 'S',
    bom: 'B',
    'muito bom': 'MB',
};

const defaultLevels: MentionLevel[] = [
    { label: 'Fraco', from_percent: 0, inovar_code: 'F' },
    { label: 'Insuficiente', from_percent: 20, inovar_code: 'I' },
    { label: 'Suficiente', from_percent: 50, inovar_code: 'S' },
    { label: 'Bom', from_percent: 70, inovar_code: 'B' },
    { label: 'Muito Bom', from_percent: 90, inovar_code: 'MB' },
];

/** Sem maiúsculas, acentos nem espaços nas pontas — para comparar nomes. */
function normaliseName(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .trim()
        .replace(/\s+/g, ' ')
        .toLowerCase();
}

function suggestedInovarCode(label: string): string | null {
    return defaultInovarCodes[normaliseName(label)] ?? null;
}

const selectedScale = computed(() => props.scales.find((scale) => scale.id === form.scale_id) ?? null);

/** Só uma escala de níveis se pode personalizar. */
const canCustomiseMentions = computed(
    () => selectedScale.value !== null && selectedScale.value.kind === 'level' && (selectedScale.value.levels?.length ?? 0) > 0,
);

const mentionsDialogOpen = ref(false);

const mentionsForm = useForm<{
    levels: MentionLevel[];
    first_positive_level: number;
    profile_name: string;
    scale_name: string;
    use_as_organization_default: boolean;
}>({
    levels: [],
    first_positive_level: 1,
    profile_name: '',
    scale_name: '',
    use_as_organization_default: false,
});

// O que o professor já escolheu à mão — as sugestões só valem até lá.
const inovarTouched = ref<boolean[]>([]);
const firstPositiveTouched = ref(false);
const scaleNameTouched = ref(false);

function cutOf(level: MentionLevel): number | null {
    const cut = typeof level.from_percent === 'number' ? level.from_percent : Number(level.from_percent);

    return level.from_percent !== '' && Number.isInteger(cut) ? cut : null;
}

/** A sugestão: o primeiro nível cujo corte é ≥ 50 %; sem ele, o último. */
function suggestedFirstPositive(levels: MentionLevel[]): number {
    const index = levels.findIndex((level) => (cutOf(level) ?? 0) >= 50);

    return index === -1 ? levels.length : index + 1;
}

function levelsFromScale(scale: Option): MentionLevel[] {
    return (scale.levels ?? []).map((level, index) => ({
        label: level.label,
        from_percent: index === 0 ? 0 : (level.from_percent ?? 0),
        inovar_code: level.inovar_code ?? '',
    }));
}

function openMentionsDialog(): void {
    const scale = selectedScale.value;

    if (!scale) {
        return;
    }

    mentionsForm.clearErrors();
    mentionsForm.levels = levelsFromScale(scale);
    inovarTouched.value = mentionsForm.levels.map(
        (level) => level.inovar_code !== '' && level.inovar_code !== (suggestedInovarCode(level.label) ?? ''),
    );

    const levels = scale.levels ?? [];
    const firstPositive = levels.findIndex((level) => level.is_negative === false);
    mentionsForm.first_positive_level = firstPositive === -1 ? levels.length : firstPositive + 1;
    firstPositiveTouched.value = mentionsForm.first_positive_level !== suggestedFirstPositive(mentionsForm.levels);

    mentionsForm.profile_name = form.name;
    mentionsForm.scale_name = scale.system ? `Escala 1 a ${levels.length} · ${form.name}`.trim() : scale.label;
    scaleNameTouched.value = false;
    mentionsForm.use_as_organization_default = scale.organization_default === true;
    mentionsDialogOpen.value = true;
}

/** O nome pré-preenchido acompanha o número de níveis, enquanto o professor não lhe mexer. */
function syncScaleName(): void {
    if (!scaleNameTouched.value) {
        mentionsForm.scale_name = mentionsForm.scale_name.replace(
            /^Escala 1 a \d+ · /,
            `Escala 1 a ${mentionsForm.levels.length} · `,
        );
    }
}

function syncFirstPositive(): void {
    if (!firstPositiveTouched.value) {
        mentionsForm.first_positive_level = suggestedFirstPositive(mentionsForm.levels);
    }
}

/** Ao escrever um nome que coincide com um dos de origem, o código sugere-se. */
function onLabelInput(index: number, event: Event): void {
    const suggestion = suggestedInovarCode((event.target as HTMLInputElement).value);

    if (suggestion !== null && !inovarTouched.value[index]) {
        mentionsForm.levels[index].inovar_code = suggestion;
    }
}

// A sugestão da fronteira recalcula-se quando os cortes mudam.
watch(
    () => mentionsForm.levels.map((level) => level.from_percent),
    () => syncFirstPositive(),
);

const canAddLevel = computed(() => {
    const last = mentionsForm.levels[mentionsForm.levels.length - 1];

    return mentionsForm.levels.length < MAX_LEVELS && (last === undefined || (cutOf(last) ?? 0) < 100);
});

function addLevel(): void {
    if (!canAddLevel.value) {
        return;
    }

    const last = cutOf(mentionsForm.levels[mentionsForm.levels.length - 1]) ?? 0;
    const suggested = Math.min(100, Math.max(last + 1, Math.round((last + 100) / 2)));

    mentionsForm.levels.push({ label: '', from_percent: suggested, inovar_code: '' });
    inovarTouched.value.push(false);
    syncFirstPositive();
    syncScaleName();
}

function removeLevel(index: number): void {
    if (mentionsForm.levels.length <= MIN_LEVELS) {
        return;
    }

    mentionsForm.levels.splice(index, 1);
    inovarTouched.value.splice(index, 1);

    // O novo primeiro nível começa sempre em 0.
    mentionsForm.levels[0].from_percent = 0;

    if (firstPositiveTouched.value) {
        const position = index + 1;
        const current = mentionsForm.first_positive_level;
        const shifted = position < current ? current - 1 : current;

        mentionsForm.first_positive_level = Math.min(mentionsForm.levels.length, Math.max(1, shifted));
    }

    syncFirstPositive();
    syncScaleName();
}

/** «Repor a escala do Lapispro»: os cinco níveis de origem. */
function resetMentions(): void {
    const origin = props.scales.find(
        (scale) => scale.system === true && scale.kind === 'level' && (scale.levels?.length ?? 0) > 0,
    );
    const levels = origin ? levelsFromScale(origin) : defaultLevels.map((level) => ({ ...level }));

    mentionsForm.clearErrors();
    mentionsForm.levels = levels;
    inovarTouched.value = levels.map(() => false);
    firstPositiveTouched.value = false;
    syncFirstPositive();
    syncScaleName();
}

/** «20 – 49 %»: o intervalo que os cortes dão a cada nível. */
function rangeText(index: number): string {
    const from = cutOf(mentionsForm.levels[index]);
    const next = mentionsForm.levels[index + 1];
    const nextCut = next === undefined ? 101 : cutOf(next);

    if (from === null || nextCut === null || nextCut <= from) {
        return '';
    }

    return `${from} – ${nextCut - 1} %`;
}

const serverErrors = computed(() => mentionsForm.errors as Record<string, string>);

const REPEATED_NAME = 'Dois níveis não podem ter o mesmo nome.';

/** Feedback imediato (nome repetido); o servidor continua a ser a autoridade. */
function labelError(index: number): string | undefined {
    const name = normaliseName(mentionsForm.levels[index].label);
    const repeated = name !== '' && mentionsForm.levels.findIndex((level) => normaliseName(level.label) === name) !== index;

    return serverErrors.value[`levels.${index}.label`] ?? (repeated ? REPEATED_NAME : undefined);
}

function cutError(index: number): string | undefined {
    if (serverErrors.value[`levels.${index}.from_percent`]) {
        return serverErrors.value[`levels.${index}.from_percent`];
    }

    if (index === 0) {
        return undefined;
    }

    const cut = cutOf(mentionsForm.levels[index]);
    const previous = cutOf(mentionsForm.levels[index - 1]);

    if (cut === null || cut < 0 || cut > 100) {
        return 'Indique um número inteiro entre 0 e 100.';
    }

    return previous !== null && cut <= previous ? 'Tem de começar acima do nível anterior.' : undefined;
}

function codeError(index: number): string | undefined {
    const code = mentionsForm.levels[index].inovar_code;

    if (serverErrors.value[`levels.${index}.inovar_code`]) {
        return serverErrors.value[`levels.${index}.inovar_code`];
    }

    if (code.length > INOVAR_CODE_MAX_LENGTH) {
        return `No máximo ${INOVAR_CODE_MAX_LENGTH} caracteres.`;
    }

    return /\s/.test(code) ? 'Sem espaços.' : undefined;
}

const levelsWithoutCode = computed(() => mentionsForm.levels.some((level) => level.inovar_code.trim() === ''));

const repeatedCodes = computed(() => {
    const codes = mentionsForm.levels.map((level) => level.inovar_code.trim().toLowerCase()).filter((code) => code !== '');

    return new Set(codes).size !== codes.length;
});

function submitMentions(): void {
    const source = selectedScale.value;

    if (!source) {
        return;
    }

    const known = new Set(props.scales.map((scale) => scale.id));
    mentionsForm.profile_name = form.name;

    mentionsForm.post(`/scales/${source.ulid}/mencoes`, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            // A lista de escalas voltou com a derivada: nova (apareceu uma
            // escala que não existia) ou a mesma de antes, atualizada em cima.
            const appeared = props.scales.find((scale) => !known.has(scale.id));

            form.scale_id = appeared?.id ?? source.id;
            mentionsDialogOpen.value = false;
        },
    });
}

function addDomain(): void {
    form.domains.push({ name: '', weight: 0 });
}

function removeDomain(index: number): void {
    form.domains.splice(index, 1);
}

function domainError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string>)[`domains.${index}.${field}`];
}

// The server validates the list (`grade_levels`) and each element
// (`grade_levels.0`, …: max 16 characters, no duplicates). Both belong to this
// one field, so the messages are shown together, never silently dropped.
const gradeLevelsError = computed(() => {
    const messages = Object.entries(form.errors as Record<string, string>)
        .filter(([key]) => key === 'grade_levels' || /^grade_levels\.\d+$/.test(key))
        .map(([, message]) => message);

    return messages.length === 0 ? undefined : [...new Set(messages)].join(' ');
});

function submit(): void {
    // What was typed but not yet «Adicionado» is still part of the field: sending
    // the form without it would silently save a profile with no grade level.
    if (gradeLevelInput.value.trim() !== '') {
        addGradeLevel();
    }

    form.submit(props.method, props.submitUrl, { preserveScroll: true });
}
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2 sm:col-span-2">
                <Label for="name">Nome do perfil</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    placeholder="Ex.: Português – 7.º Ano – Escala 1 a 5"
                    :disabled="!canManage"
                />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="academic_year_id">Ano letivo</Label>
                <select
                    id="academic_year_id"
                    v-model.number="form.academic_year_id"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    :disabled="!canManage"
                >
                    <option :value="null" disabled>Escolher…</option>
                    <option
                        v-for="year in academicYears"
                        :key="year.id"
                        :value="year.id"
                    >
                        {{ year.label }}
                    </option>
                </select>
                <InputError :message="form.errors.academic_year_id" />
            </div>
            <div class="grid gap-2">
                <Label for="subject_id">Disciplina</Label>
                <select
                    id="subject_id"
                    v-model.number="form.subject_id"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    :disabled="!canManage"
                >
                    <option :value="null" disabled>Escolher…</option>
                    <option
                        v-for="subject in subjects"
                        :key="subject.id"
                        :value="subject.id"
                    >
                        {{ subject.label }}
                    </option>
                </select>
                <InputError :message="form.errors.subject_id" />
            </div>
            <div class="grid gap-2">
                <Label for="grade_level_input">Ano(s) de escolaridade</Label>
                <div class="flex gap-2">
                    <Input
                        id="grade_level_input"
                        v-model="gradeLevelInput"
                        placeholder="Ex.: 7.º"
                        :disabled="!canManage"
                        @keydown.enter.prevent="addGradeLevel"
                    />
                    <Button
                        v-if="canManage"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="addGradeLevel"
                    >
                        <Plus class="size-4" /> Adicionar
                    </Button>
                </div>
                <div v-if="form.grade_levels.length" class="flex flex-wrap gap-2">
                    <Badge
                        v-for="(gradeLevel, index) in form.grade_levels"
                        :key="gradeLevel"
                        variant="secondary"
                        class="gap-1.5"
                    >
                        {{ gradeLevel }}
                        <button
                            v-if="canManage"
                            type="button"
                            class="rounded-full hover:text-destructive"
                            :aria-label="`Remover ${gradeLevel}`"
                            @click="removeGradeLevel(index)"
                        >
                            <X class="size-3" />
                        </button>
                    </Badge>
                </div>
                <InputError :message="gradeLevelsError" />
            </div>
            <div class="grid gap-2">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <Label for="scale_id">Escala</Label>
                    <div v-if="canManage" class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="!canCustomiseMentions"
                            :title="canCustomiseMentions ? undefined : 'Só uma escala de níveis se pode personalizar'"
                            @click="openMentionsDialog"
                        >
                            <Pencil class="size-4" /> Personalizar escala
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="openScaleDialog"
                        >
                            <Plus class="size-4" /> Criar escala personalizada
                        </Button>
                    </div>
                </div>
                <select
                    id="scale_id"
                    v-model.number="form.scale_id"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    :disabled="!canManage"
                >
                    <option :value="null" disabled>Escolher…</option>
                    <optgroup label="Escalas do sistema">
                        <option
                            v-for="scale in scales.filter(
                                (item) => item.system === true,
                            )"
                            :key="scale.id"
                            :value="scale.id"
                        >
                            {{ scale.label }}
                        </option>
                    </optgroup>
                    <optgroup label="Escalas personalizadas">
                        <option
                            v-for="scale in scales.filter(
                                (item) => item.system === false,
                            )"
                            :key="scale.id"
                            :value="scale.id"
                        >
                            {{ scale.label }}{{ scale.organization_default ? ' (escala da organização)' : '' }}
                        </option>
                    </optgroup>
                </select>
                <InputError :message="form.errors.scale_id" />
            </div>
        </section>

        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-semibold">
                        Domínios e ponderações
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        A soma das ponderações tem de ser 100% para ativar o
                        perfil.
                    </p>
                </div>
                <Button
                    v-if="canManage"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addDomain"
                >
                    <Plus class="size-4" /> Adicionar domínio
                </Button>
            </div>
            <InputError :message="form.errors.domains" />

            <div class="space-y-2">
                <div
                    v-for="(domain, index) in form.domains"
                    :key="index"
                    class="grid items-end gap-3 rounded-lg border border-border p-3 sm:grid-cols-[1fr_8rem_auto]"
                >
                    <div class="grid gap-1.5">
                        <Label :for="`domain-name-${index}`" class="text-xs"
                            >Domínio</Label
                        >
                        <Input
                            :id="`domain-name-${index}`"
                            v-model="domain.name"
                            placeholder="Ex.: Leitura"
                            :disabled="!canManage"
                        />
                        <InputError :message="domainError(index, 'name')" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`domain-weight-${index}`" class="text-xs"
                            >Ponderação (%)</Label
                        >
                        <Input
                            :id="`domain-weight-${index}`"
                            v-model.number="domain.weight"
                            type="number"
                            min="0"
                            max="100"
                            step="0.5"
                            :disabled="!canManage"
                        />
                        <InputError :message="domainError(index, 'weight')" />
                    </div>
                    <Button
                        v-if="canManage"
                        type="button"
                        variant="ghost"
                        size="icon"
                        :disabled="form.domains.length <= 1"
                        aria-label="Remover domínio"
                        @click="removeDomain(index)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
            </div>

            <div
                class="flex items-center justify-between rounded-lg border px-4 py-2.5 text-sm"
                :class="
                    weightsOk
                        ? 'border-emerald-300 bg-emerald-50 text-emerald-900'
                        : 'border-amber-300 bg-amber-50 text-amber-900'
                "
            >
                <span class="font-medium">Total das ponderações</span>
                <span class="font-semibold tabular-nums"
                    >{{ totalWeight }}%
                    {{ weightsOk ? '✓' : '(tem de ser 100%)' }}</span
                >
            </div>
        </section>

        <div v-if="canManage" class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing"
                >Guardar perfil</Button
            >
            <span class="text-sm text-muted-foreground"
                >Guardar cria/atualiza um rascunho. A ativação faz-se na
                lista.</span
            >
        </div>
        <p v-else class="rounded-md border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
            Este perfil é gerido pelo responsável da organização.
        </p>
    </form>

    <Dialog v-if="canManage" v-model:open="scaleDialogOpen">
        <DialogContent>
            <form @submit.prevent="submitScale">
                <DialogHeader>
                    <DialogTitle>Criar escala personalizada</DialogTitle>
                    <DialogDescription
                        >Defina o nome e a gama numérica da
                        escala.</DialogDescription
                    >
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label for="scale-name">Nome</Label>
                        <Input id="scale-name" v-model="scaleForm.name" />
                        <InputError :message="scaleForm.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="scale-min-value">Valor mínimo</Label>
                        <Input
                            id="scale-min-value"
                            :model-value="scaleForm.min_value ?? undefined"
                            type="number"
                            step="any"
                            @update:model-value="
                                scaleForm.min_value =
                                    $event === undefined || $event === ''
                                        ? null
                                        : Number($event)
                            "
                        />
                        <InputError :message="scaleForm.errors.min_value" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="scale-max-value">Valor máximo</Label>
                        <Input
                            id="scale-max-value"
                            :model-value="scaleForm.max_value ?? undefined"
                            type="number"
                            step="any"
                            @update:model-value="
                                scaleForm.max_value =
                                    $event === undefined || $event === ''
                                        ? null
                                        : Number($event)
                            "
                        />
                        <InputError :message="scaleForm.errors.max_value" />
                    </div>
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="scaleForm.processing"
                        >Criar escala</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- A escala da escola: quantos níveis, como se chamam, a partir de que
         percentagem começa cada um e o código que a escola definiu no Inovar. -->
    <Dialog v-if="canManage" v-model:open="mentionsDialogOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
            <form data-test="mentions-form" @submit.prevent="submitMentions">
                <DialogHeader>
                    <DialogTitle>Personalizar escala</DialogTitle>
                    <DialogDescription>
                        Os níveis que a sua escola usa: quantos são, como se chamam e a partir de que percentagem
                        começa cada um.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div class="grid gap-1">
                        <Label for="scale-display-name">Nome da escala</Label>
                        <Input
                            id="scale-display-name"
                            v-model="mentionsForm.scale_name"
                            maxlength="120"
                            @input="scaleNameTouched = true"
                        />
                        <InputError :message="serverErrors.scale_name" />
                    </div>

                    <label class="flex items-start gap-2 text-sm">
                        <input
                            v-model="mentionsForm.use_as_organization_default"
                            type="checkbox"
                            class="mt-0.5 size-4"
                            data-test="organization-default"
                        />
                        <span>Usar como escala da organização — os perfis novos abrem com ela</span>
                    </label>

                    <div class="grid gap-3">
                        <div
                            class="hidden grid-cols-[1.5rem_1fr_6rem_6rem_2rem] items-end gap-2 text-xs text-muted-foreground sm:grid"
                        >
                            <span>N.º</span>
                            <span>Designação</span>
                            <span>A partir de (%)</span>
                            <span>Inovar</span>
                            <span />
                        </div>

                        <div
                            v-for="(level, index) in mentionsForm.levels"
                            :key="index"
                            class="grid grid-cols-[1.5rem_1fr_5rem_5rem_2rem] items-start gap-2"
                            data-test="level-row"
                        >
                            <span class="pt-2 text-center font-semibold tabular-nums">{{ index + 1 }}</span>
                            <div class="grid gap-1">
                                <Input
                                    :id="`level-label-${index}`"
                                    v-model="level.label"
                                    maxlength="64"
                                    :aria-label="`Designação do nível ${index + 1}`"
                                    @input="onLabelInput(index, $event)"
                                />
                                <p v-if="rangeText(index)" class="text-xs text-muted-foreground" data-test="level-range">
                                    {{ rangeText(index) }}
                                </p>
                                <InputError :message="labelError(index)" />
                            </div>
                            <div class="grid gap-1">
                                <Input
                                    :id="`level-cut-${index}`"
                                    v-model.number="level.from_percent"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="1"
                                    :disabled="index === 0"
                                    :aria-label="`Percentagem a partir da qual começa o nível ${index + 1}`"
                                />
                                <InputError :message="cutError(index)" />
                            </div>
                            <div class="grid gap-1">
                                <Input
                                    :id="`level-inovar-${index}`"
                                    v-model="level.inovar_code"
                                    :maxlength="INOVAR_CODE_MAX_LENGTH"
                                    :aria-label="`Código no Inovar do nível ${index + 1}`"
                                    @input="inovarTouched[index] = true"
                                />
                                <InputError :message="codeError(index)" />
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :disabled="mentionsForm.levels.length <= MIN_LEVELS"
                                :aria-label="`Remover o nível ${index + 1}`"
                                data-test="remove-level"
                                @click="removeLevel(index)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>

                        <p class="text-xs text-muted-foreground">
                            Código no Inovar — o que a sua escola definiu no Inovar para este nível.
                        </p>
                        <p v-if="levelsWithoutCode" class="text-xs text-amber-700 dark:text-amber-400" data-test="warning-no-code">
                            Sem código em todos os níveis, esta escala não exporta para o Inovar.
                        </p>
                        <p v-if="repeatedCodes" class="text-xs text-amber-700 dark:text-amber-400" data-test="warning-repeated-code">
                            Dois níveis têm o mesmo código: no Inovar ficam indistinguíveis.
                        </p>
                        <InputError :message="serverErrors.levels" />

                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="!canAddLevel"
                                data-test="add-level"
                                @click="addLevel"
                            >
                                <Plus class="size-4" /> Adicionar nível
                            </Button>
                            <Button type="button" variant="ghost" size="sm" data-test="reset-levels" @click="resetMentions">
                                Repor a escala do Lapispro
                            </Button>
                        </div>
                    </div>

                    <div class="grid gap-1">
                        <Label for="first-positive-level">A partir de que nível é positiva</Label>
                        <select
                            id="first-positive-level"
                            v-model.number="mentionsForm.first_positive_level"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                            @change="firstPositiveTouched = true"
                        >
                            <option v-for="(level, index) in mentionsForm.levels" :key="index" :value="index + 1">
                                {{ index + 1 }} · {{ level.label || 'Sem nome' }}
                            </option>
                        </select>
                        <InputError :message="serverErrors.first_positive_level" />
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="mentionsDialogOpen = false">Cancelar</Button>
                    <Button type="submit" :disabled="mentionsForm.processing">Guardar</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
