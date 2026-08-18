<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, Eye, Pencil, RefreshCw, RotateCcw, Trash2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import ReportLetterhead from '@/components/reports/ReportLetterhead.vue';
import ReportSectionData from '@/components/reports/ReportSectionData.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type ReportPayload = {
    ulid: string;
    title: string;
    type: string;
    type_label: string;
    status: string;
    status_label: string;
    tone: string;
    scope_label: string;
    scope_kind: string;
    subject_label: string;
    teacher_input: Record<string, unknown>;
    name_students: boolean;
    author: string | null;
    updated_at: string;
    finalized_at: string | null;
    finalized_by: string | null;
    based_on: { ulid: string; title: string } | null;
};

type SectionPayload = {
    ulid: string;
    key: string;
    heading: string;
    position: number;
    included: boolean;
    body: string | null;
    edited: boolean;
    can_restore: boolean;
    has_content: boolean;
    sources: string[];
    data: Record<string, unknown> | null;
};

type Identity = {
    name: string;
    header_lines: string[];
    footer_note: string | null;
    logo_url: string | null;
    is_configured: boolean;
};

type Characterisation = {
    available: boolean;
    behaviour?: Option[];
    attitude?: Option[];
    indicators?: Option[];
    standings?: Option[];
    planning: Option[];
};

const props = defineProps<{
    report: ReportPayload;
    sections: SectionPayload[];
    identity: Identity;
    characterisation: Characterisation;
    can: { update: boolean; finalize: boolean; delete: boolean; export: boolean };
}>();

const mode = ref<'edit' | 'preview'>('edit');
const editing = ref<string | null>(null);
const draftBody = ref('');

const printable = computed(() => props.sections.filter((section) => section.included && section.has_content));

const isDraft = computed(() => props.report.status === 'draft');

// ------------------------------------------------------------------ envelope

const titleForm = useForm({ title: props.report.title });

function saveTitle() {
    titleForm.put(`/reports/${props.report.ulid}`, { preserveScroll: true });
}

// ---------------------------------------------------------- characterisation

type IndicatorChoice = { indicator: string; standing: string };

const input = props.report.teacher_input as {
    behaviour?: string;
    attitude?: string;
    indicators?: IndicatorChoice[];
    observation?: string;
    planning?: {
        compliance?: string;
        pending_content?: string[];
        postponed_content?: string[];
        reason?: string;
        recovery_plan?: string;
        note?: string;
    };
    final_note?: string;
};

const characterisationForm = useForm({
    teacher_input: {
        behaviour: input.behaviour ?? '',
        attitude: input.attitude ?? '',
        indicators: (input.indicators ?? []) as IndicatorChoice[],
        observation: input.observation ?? '',
        planning: {
            compliance: input.planning?.compliance ?? '',
            pending_content: (input.planning?.pending_content ?? []).join('\n'),
            postponed_content: (input.planning?.postponed_content ?? []).join('\n'),
            reason: input.planning?.reason ?? '',
            recovery_plan: input.planning?.recovery_plan ?? '',
            note: input.planning?.note ?? '',
        },
        final_note: input.final_note ?? '',
    },
});

function standingOf(indicator: string): string | null {
    return characterisationForm.teacher_input.indicators.find((row) => row.indicator === indicator)?.standing ?? null;
}

function setStanding(indicator: string, standing: string) {
    const rows = characterisationForm.teacher_input.indicators;
    const index = rows.findIndex((row) => row.indicator === indicator);

    if (standing === '') {
        if (index !== -1) {
            rows.splice(index, 1);
        }

        return;
    }

    if (index === -1) {
        rows.push({ indicator, standing });
    } else {
        rows[index].standing = standing;
    }
}

function saveCharacterisation() {
    characterisationForm
        .transform((data) => ({
            teacher_input: {
                ...data.teacher_input,
                // Empty means "unanswered", and unanswered must reach the server
                // as absent — never as a value that would print a sentence.
                behaviour: data.teacher_input.behaviour || null,
                attitude: data.teacher_input.attitude || null,
                observation: data.teacher_input.observation || null,
                final_note: data.teacher_input.final_note || null,
                planning: {
                    ...data.teacher_input.planning,
                    compliance: data.teacher_input.planning.compliance || null,
                    pending_content: splitLines(data.teacher_input.planning.pending_content),
                    postponed_content: splitLines(data.teacher_input.planning.postponed_content),
                    reason: data.teacher_input.planning.reason || null,
                    recovery_plan: data.teacher_input.planning.recovery_plan || null,
                    note: data.teacher_input.planning.note || null,
                },
            },
        }))
        .put(`/reports/${props.report.ulid}`, { preserveScroll: true });
}

function splitLines(value: string): string[] {
    return value
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');
}

const planningNeedsDetail = computed(() =>
    ['partially_complied', 'not_complied'].includes(characterisationForm.teacher_input.planning.compliance),
);

// ------------------------------------------------------------------ sections

function startEditing(section: SectionPayload) {
    editing.value = section.ulid;
    draftBody.value = section.body ?? '';
}

function cancelEditing() {
    editing.value = null;
    draftBody.value = '';
}

function saveSection(section: SectionPayload) {
    router.put(
        `/reports/${props.report.ulid}/seccoes/${section.ulid}`,
        { body: draftBody.value },
        { preserveScroll: true, onSuccess: cancelEditing },
    );
}

function toggleIncluded(section: SectionPayload) {
    router.put(
        `/reports/${props.report.ulid}/seccoes/${section.ulid}`,
        { included: !section.included },
        { preserveScroll: true },
    );
}

function regenerateSection(section: SectionPayload) {
    router.post(`/reports/${props.report.ulid}/seccoes/${section.ulid}/gerar`, {}, { preserveScroll: true });
}

function restoreSection(section: SectionPayload) {
    router.post(`/reports/${props.report.ulid}/seccoes/${section.ulid}/restaurar`, {}, { preserveScroll: true });
}

function regenerateAll() {
    router.post(`/reports/${props.report.ulid}/gerar`, {}, { preserveScroll: true });
}

function destroyReport() {
    router.delete(`/reports/${props.report.ulid}`);
}
</script>

<template>
    <Head :title="report.title" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <Link href="/reports" class="text-sm text-muted-foreground hover:underline">← Relatórios</Link>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="report.title"
                :description="`${report.type_label} · ${report.subject_label} · ${report.scope_label}`"
            />

            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="rounded-full px-2.5 py-1 text-xs font-medium"
                    :class="
                        isDraft
                            ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400'
                            : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                    "
                >
                    {{ report.status_label }}
                </span>

                <div class="flex overflow-hidden rounded-md border border-border">
                    <button
                        type="button"
                        class="flex items-center gap-1.5 px-3 py-1.5 text-xs"
                        :class="mode === 'edit' ? 'bg-muted font-medium' : ''"
                        @click="mode = 'edit'"
                    >
                        <Pencil class="size-3.5" />
                        Editar
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 px-3 py-1.5 text-xs"
                        :class="mode === 'preview' ? 'bg-muted font-medium' : ''"
                        @click="mode = 'preview'"
                    >
                        <Eye class="size-3.5" />
                        Pré-visualizar
                    </button>
                </div>
            </div>
        </div>

        <!-- ============================================================ EDIT -->
        <template v-if="mode === 'edit'">
            <section v-if="can.update" class="space-y-3 rounded-lg border border-border p-4">
                <div class="grid gap-2">
                    <Label for="title">Título</Label>
                    <div class="flex gap-2">
                        <Input id="title" v-model="titleForm.title" class="flex-1" />
                        <Button variant="outline" :disabled="titleForm.processing" @click="saveTitle">Guardar</Button>
                    </div>
                </div>
            </section>

            <!-- ------------------------------------------- caracterização -->
            <section v-if="can.update" class="space-y-5 rounded-lg border border-border p-4">
                <div>
                    <h2 class="font-medium">Caracterização</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        O LÁPIS não dispõe de informação para caracterizar comportamento, atitude ou cumprimento da
                        planificação. O que indicar aqui é seu — e é o que dá origem às secções correspondentes.
                    </p>
                </div>

                <template v-if="characterisation.available">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="behaviour">Comportamento</Label>
                            <select
                                id="behaviour"
                                v-model="characterisationForm.teacher_input.behaviour"
                                class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                            >
                                <option value="">Sem resposta</option>
                                <option v-for="option in characterisation.behaviour" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="attitude">Atitude face às aprendizagens</Label>
                            <select
                                id="attitude"
                                v-model="characterisationForm.teacher_input.attitude"
                                class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                            >
                                <option value="">Sem resposta</option>
                                <option v-for="option in characterisation.attitude" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <Label>Aspetos a assinalar</Label>
                        <p class="text-xs text-muted-foreground">
                            Só entram no relatório os que assinalar, e sempre com o sentido que indicar.
                        </p>
                        <ul class="divide-y divide-border overflow-hidden rounded-md border border-border">
                            <li
                                v-for="indicator in characterisation.indicators"
                                :key="indicator.value"
                                class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
                            >
                                <span>{{ indicator.label }}</span>
                                <select
                                    class="h-8 rounded-md border border-border bg-background px-2 text-xs"
                                    :value="standingOf(indicator.value) ?? ''"
                                    @change="setStanding(indicator.value, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option value="">Não assinalar</option>
                                    <option v-for="standing in characterisation.standings" :key="standing.value" :value="standing.value">
                                        {{ standing.label }}
                                    </option>
                                </select>
                            </li>
                        </ul>
                    </div>

                    <div class="grid gap-2">
                        <Label for="observation">Observação complementar</Label>
                        <textarea
                            id="observation"
                            v-model="characterisationForm.teacher_input.observation"
                            rows="3"
                            class="rounded-md border border-border bg-background p-2 text-sm"
                        ></textarea>
                    </div>
                </template>

                <p v-else class="rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
                    A caracterização de comportamento, atitude e dificuldades faz parte do plano Pro. As secções
                    descritivas do relatório não dependem dela.
                </p>

                <!-- Planning is Base: transcription, not analysis. -->
                <div class="space-y-4 border-t border-border pt-4">
                    <div class="grid gap-2">
                        <Label for="compliance">Cumprimento da planificação</Label>
                        <select
                            id="compliance"
                            v-model="characterisationForm.teacher_input.planning.compliance"
                            class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                        >
                            <option value="">Sem resposta</option>
                            <option v-for="option in characterisation.planning" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>

                    <template v-if="planningNeedsDetail">
                        <div class="grid gap-2">
                            <Label for="pending">Conteúdos não abordados (um por linha)</Label>
                            <textarea
                                id="pending"
                                v-model="characterisationForm.teacher_input.planning.pending_content"
                                rows="2"
                                class="rounded-md border border-border bg-background p-2 text-sm"
                            ></textarea>
                        </div>

                        <div class="grid gap-2">
                            <Label for="postponed">Conteúdos adiados (um por linha)</Label>
                            <textarea
                                id="postponed"
                                v-model="characterisationForm.teacher_input.planning.postponed_content"
                                rows="2"
                                class="rounded-md border border-border bg-background p-2 text-sm"
                            ></textarea>
                        </div>

                        <div class="grid gap-2">
                            <Label for="reason">Motivo</Label>
                            <Input id="reason" v-model="characterisationForm.teacher_input.planning.reason" />
                        </div>
                    </template>
                </div>

                <div class="grid gap-2 border-t border-border pt-4">
                    <Label for="final-note">Nota de síntese final</Label>
                    <textarea
                        id="final-note"
                        v-model="characterisationForm.teacher_input.final_note"
                        rows="3"
                        class="rounded-md border border-border bg-background p-2 text-sm"
                        placeholder="Perspetivas para o período seguinte, por exemplo."
                    ></textarea>
                </div>

                <Button :disabled="characterisationForm.processing" @click="saveCharacterisation">
                    Guardar e regenerar
                </Button>
            </section>

            <!-- ------------------------------------------------- as secções -->
            <section class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-medium">Secções</h2>
                    <Button v-if="can.update" variant="outline" size="sm" @click="regenerateAll">
                        <RefreshCw class="size-3.5" />
                        Regenerar tudo
                    </Button>
                </div>

                <p class="text-xs text-muted-foreground">
                    Regenerar não apaga o que reescreveu: só as secções que ainda têm o texto automático são
                    atualizadas.
                </p>

                <article
                    v-for="section in sections"
                    :key="section.ulid"
                    class="rounded-lg border p-4"
                    :class="section.included ? 'border-border' : 'border-dashed border-border bg-muted/20'"
                >
                    <header class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="font-medium">{{ section.heading }}</h3>
                            <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <span v-if="section.edited" class="rounded-full bg-blue-500/10 px-2 py-0.5 text-blue-700 dark:text-blue-400">
                                    Editada por si
                                </span>
                                <span v-if="!section.has_content">Sem conteúdo — não será impressa</span>
                            </p>
                        </div>

                        <div v-if="can.update" class="flex items-center gap-1">
                            <Button variant="ghost" size="sm" @click="toggleIncluded(section)">
                                {{ section.included ? 'Excluir' : 'Incluir' }}
                            </Button>
                            <Button
                                v-if="section.can_restore"
                                variant="ghost"
                                size="sm"
                                title="Restaurar o texto automático"
                                @click="restoreSection(section)"
                            >
                                <RotateCcw class="size-3.5" />
                            </Button>
                            <Button variant="ghost" size="sm" title="Regenerar a partir dos dados atuais" @click="regenerateSection(section)">
                                <RefreshCw class="size-3.5" />
                            </Button>
                            <Button v-if="editing !== section.ulid" variant="ghost" size="sm" @click="startEditing(section)">
                                <Pencil class="size-3.5" />
                            </Button>
                        </div>
                    </header>

                    <div v-if="editing === section.ulid" class="mt-3 space-y-2">
                        <textarea
                            v-model="draftBody"
                            rows="8"
                            class="w-full rounded-md border border-border bg-background p-3 text-sm leading-relaxed"
                        ></textarea>
                        <div class="flex gap-2">
                            <Button size="sm" @click="saveSection(section)">
                                <Check class="size-3.5" />
                                Guardar
                            </Button>
                            <Button variant="ghost" size="sm" @click="cancelEditing">
                                <X class="size-3.5" />
                                Cancelar
                            </Button>
                        </div>
                    </div>

                    <template v-else>
                        <p v-if="section.body" class="mt-3 text-sm leading-relaxed whitespace-pre-line">
                            {{ section.body }}
                        </p>
                        <p v-else class="mt-3 text-sm text-muted-foreground italic">
                            Sem texto gerado.
                        </p>

                        <ReportSectionData :section-key="section.key" :data="section.data" />
                    </template>
                </article>
            </section>

            <div v-if="can.delete" class="border-t border-border pt-6">
                <Button variant="ghost" size="sm" class="text-destructive" @click="destroyReport">
                    <Trash2 class="size-3.5" />
                    Eliminar rascunho
                </Button>
            </div>
        </template>

        <!-- ========================================================= PREVIEW -->
        <template v-else>
            <div class="overflow-hidden rounded-lg border border-border bg-card">
                <div class="mx-auto max-w-[52rem] space-y-6 p-8">
                    <ReportLetterhead :identity="identity" />

                    <div class="space-y-1 border-b border-border pb-4">
                        <h1 class="text-lg font-semibold">{{ report.title }}</h1>
                        <p class="text-sm text-muted-foreground">
                            {{ report.subject_label }} · {{ report.scope_label }}
                        </p>
                    </div>

                    <section v-for="section in printable" :key="section.ulid" class="space-y-2">
                        <h2 class="text-sm font-semibold">{{ section.heading }}</h2>
                        <p v-if="section.body" class="text-sm leading-relaxed whitespace-pre-line">
                            {{ section.body }}
                        </p>
                        <ReportSectionData :section-key="section.key" :data="section.data" />
                    </section>

                    <p v-if="printable.length === 0" class="text-sm text-muted-foreground">
                        Nenhuma secção com conteúdo. Preencha a caracterização ou verifique se existem dados no
                        período escolhido.
                    </p>

                    <p v-if="identity.footer_note" class="border-t border-border pt-4 text-xs text-muted-foreground">
                        {{ identity.footer_note }}
                    </p>
                </div>
            </div>
        </template>
    </div>
</template>
