<script setup lang="ts">
import { CalendarCheck, ListOrdered, ShieldCheck, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import {
    dayLabel,
    firstError,
    gapsSentence,
    planningSentences,
    plural,
    RENUMBERING_REASON,
    renumberedSentence,
    requestJson,
    STALE_PLAN_MESSAGE_TEACHER,
} from '@/lib/calendarReconciliation';
import type { ReconciliationClass, ReconciliationLesson, ReconciliationPlan } from '@/lib/calendarReconciliation';

/** Uma aula num dia sem aulas que não é corrigida automaticamente: fica como está, o professor decide. */
export type ReviewLesson = {
    ulid: string;
    date: string;
    time: string;
    class: string | null;
    class_label: string | null;
    group: string | null;
    reasons: { code: string; label: string }[];
    exception_titles: string[];
};

/**
 * «VER E CORRIGIR» — o que acontece às aulas das SUAS turmas que caem em dias
 * sem aulas, antes de confirmar. Trata o professor por «tu», como os ecrãs
 * vizinhos de «Aulas e Sumários».
 *
 * Por turma, e por palavras: o que vai ser corrigido (aulas vazias retiradas),
 * para onde passa o planeamento (o RESULTADO: uma linha por aula retirada e, à
 * parte, o planeamento que já estava nas aulas seguintes e avança — nunca a
 * cascata de passos), a numeração (que aulas por dar mudam de número e
 * porquê) e o que precisa de revisão. Nunca mostra texto de sumários,
 * planeamentos ou notas: o servidor não o envia — só datas e turmas.
 *
 * A LISTA «PARA REVER» É A DA PRÓPRIA PRÉ-VISUALIZAÇÃO, e não a do aviso
 * carregado com a página. Sem pré-visualização (`canRegularize` falso: ano
 * letivo fechado, módulo só para consulta, sessão de suporte) ou quando já só
 * há aulas para rever (`reviewOnly`), é só a lista que o aviso recebeu — não há
 * nada a confirmar, e não se promete correção.
 */
const props = defineProps<{
    canRegularize: boolean;
    reviewLessons: ReviewLesson[];
    /** Porque é que não se pode corrigir daqui (ver LessonCalendarConflictController). */
    blockedReason?: 'year_closed' | 'read_only' | 'support_session' | null;
    /** Já não há nada que se corrija automaticamente: só a lista para rever. */
    reviewOnly?: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ done: [message: string] }>();

const plan = ref<ReconciliationPlan | null>(null);
const loading = ref(false);
const submitting = ref(false);
const error = ref<string | null>(null);
/** A pré-visualização falhou e tentar outra vez pode resultar (não é uma recusa por permissão). */
const retryable = ref(false);
/** Guarda de pedidos concorrentes: só a resposta do último pedido conta. */
let requestCounter = 0;

/** Pede a pré-visualização: só quando se pode corrigir daqui e ainda há o que corrigir. */
const previews = computed(() => props.canRegularize && !props.reviewOnly);

async function requestPreview(): Promise<void> {
    const current = ++requestCounter;
    loading.value = true;
    plan.value = null;
    error.value = null;
    retryable.value = false;

    try {
        const result = await requestJson<ReconciliationPlan>('/lessons/calendar-conflicts/preview', 'POST', {});

        if (current !== requestCounter) {
            return;
        }

        if (result.status === 422) {
            error.value = firstError(result.body) ?? 'Não foi possível ver as aulas. Nada foi alterado.';
            retryable.value = true;

            return;
        }

        if (result.status === 403) {
            error.value = forbiddenMessage(result.body);

            return;
        }

        if (!result.ok || result.body === null) {
            error.value = 'Não foi possível ver as aulas. Nada foi alterado.';
            retryable.value = true;

            return;
        }

        plan.value = result.body;
    } catch {
        if (current === requestCounter) {
            error.value = 'Não foi possível ver as aulas: a ligação falhou. Nada foi alterado.';
            retryable.value = true;
        }
    } finally {
        if (current === requestCounter) {
            loading.value = false;
        }
    }
}

watch(
    open,
    (isOpen) => {
        if (!isOpen) {
            requestCounter++;
            loading.value = false;
            submitting.value = false;
            plan.value = null;
            error.value = null;
            retryable.value = false;

            return;
        }

        error.value = null;

        if (previews.value) {
            void requestPreview();
        }
    },
    { immediate: true },
);

const canConfirm = computed(
    () => previews.value && plan.value !== null && plan.value.changes_anything && !loading.value && !submitting.value,
);

/** «Confirmar e corrigir» só aparece enquanto pode haver alguma coisa a corrigir. */
const showsConfirm = computed(() => previews.value && (plan.value === null || plan.value.changes_anything));

/** Uma recusa por permissão (sessão de suporte, módulo só para consulta): tentar outra vez não muda nada. */
function forbiddenMessage(body: unknown): string {
    const message = (body as { message?: string } | null)?.message;

    return message && message !== 'This action is unauthorized.' && message !== 'Forbidden'
        ? `${message} Nada foi alterado.`
        : 'Não tens permissão para corrigir estas aulas. Nada foi alterado.';
}

function lessonsOf(item: ReconciliationClass, kind: 'A' | 'B'): ReconciliationLesson[] {
    return (item.lessons ?? []).filter((lesson) => lesson.kind === kind);
}

/** As C que ficam só para a correção seguinte (demasiadas B de uma vez). */
function isLater(lesson: { reasons: { code: string }[] }): boolean {
    return lesson.reasons.length === 1 && lesson.reasons[0].code === 'batch_limit';
}

function laterCount(item: ReconciliationClass): number {
    return (item.lessons ?? []).filter((lesson) => lesson.kind === 'C' && isLater(lesson)).length;
}

function reviewCount(item: ReconciliationClass): number {
    return (item.lessons ?? []).filter((lesson) => lesson.kind === 'C' && !isLater(lesson)).length;
}

/**
 * As aulas para rever uma a uma: as C da pré-visualização (sem as que só
 * esperam pela correção seguinte); sem pré-visualização, as do aviso.
 */
const reviewList = computed<ReviewLesson[]>(() => {
    if (plan.value === null) {
        return props.reviewLessons.filter((lesson) => !isLater(lesson));
    }

    return plan.value.classes.flatMap((item) =>
        (item.lessons ?? [])
            .filter((lesson) => lesson.kind === 'C' && !isLater(lesson))
            .map((lesson) => ({
                ulid: lesson.ulid,
                date: lesson.date,
                time: lesson.time,
                class: item.class,
                class_label: item.class_label,
                group: lesson.group,
                reasons: lesson.reasons,
                exception_titles: lesson.exception_titles,
            })),
    );
});

/**
 * As frases do planeamento de uma turma. Sem o resultado no plano (resposta
 * antiga), uma linha por aula retirada com o primeiro destino.
 */
function planningOf(item: ReconciliationClass): { retired: string[]; advanced: string[] } {
    if (item.planning !== undefined) {
        return planningSentences(item.planning);
    }

    return {
        retired: lessonsOf(item, 'B').map((lesson) =>
            lesson.moves.length > 0
                ? `O planeamento de ${dayLabel(lesson.date)} passa para ${dayLabel(lesson.moves[0].to_date)}${lesson.moves[0].new_lesson ? ' (aula criada a partir do horário)' : ''}.`
                : `O planeamento de ${dayLabel(lesson.date)} fica guardado como planeamento pendente, porque não há mais aulas no horário.`,
        ),
        advanced: [],
    };
}

function changesNumbering(item: ReconciliationClass): boolean {
    return item.renumbered > 0 || item.gaps.length > 0;
}

function whenLabel(lesson: { date: string; time: string }): string {
    return `${dayLabel(lesson.date)}, ${lesson.time}`;
}

function lessonContext(lesson: ReviewLesson): string {
    return [lesson.class_label, lesson.group].filter((part): part is string => !!part).join(' · ');
}

async function confirm(): Promise<void> {
    if (!canConfirm.value || plan.value === null) {
        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        const result = await requestJson<ReconciliationPlan>('/lessons/calendar-conflicts/regularize', 'POST', {
            plan_token: plan.value.plan_token,
        });

        if (result.status === 422) {
            const stale = (result.body as { errors?: Record<string, unknown> } | null)?.errors?.plan_token !== undefined;
            const message = stale
                ? STALE_PLAN_MESSAGE_TEACHER
                : (firstError(result.body) ?? 'Não foi possível corrigir. Nada foi alterado.');

            submitting.value = false;
            await requestPreview();
            error.value = error.value ?? message;

            return;
        }

        if (result.status === 403) {
            error.value = forbiddenMessage(result.body);
            submitting.value = false;

            return;
        }

        if (!result.ok || result.body === null) {
            error.value = 'Não foi possível corrigir. Nada foi alterado — tenta outra vez.';
            submitting.value = false;

            return;
        }

        const message = result.body.message ?? 'As aulas foram corrigidas.';

        submitting.value = false;
        open.value = false;
        emit('done', message);
    } catch {
        error.value = 'Não foi possível corrigir: a ligação falhou. Nada foi alterado.';
        submitting.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[90vh] overflow-y-auto pb-0 sm:max-w-2xl"
            :show-close-button="false"
            data-testid="regularize-dialog"
        >
            <!-- O «Fechar» do canto, com alvo de 44 px (o do componente partilhado tem 16 px). -->
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="absolute top-2 right-2 size-11"
                aria-label="Fechar"
                data-testid="regularize-x"
                @click="open = false"
            >
                <X class="size-4" aria-hidden="true" />
            </Button>

            <DialogHeader class="space-y-2 pr-8">
                <DialogTitle>{{ reviewOnly ? 'Aulas para rever' : 'Aulas em dias sem aulas' }}</DialogTitle>
                <DialogDescription>
                    <template v-if="reviewOnly && canRegularize">
                        Já foi corrigido o que podia ser corrigido automaticamente.
                        {{
                            reviewList.length === 1
                                ? 'Esta aula das tuas turmas cai num dia sem aulas e fica como está: abre-a e decide.'
                                : 'Estas aulas das tuas turmas caem em dias sem aulas e ficam como estão: abre cada uma e decide.'
                        }}
                    </template>
                    <template v-else-if="canRegularize">
                        Vê o que acontece às aulas das tuas turmas que caem em feriados e interrupções. Nada
                        muda até confirmares.
                    </template>
                    <template v-else>
                        {{
                            reviewList.length === 1
                                ? 'Esta aula das tuas turmas cai num feriado ou numa interrupção.'
                                : 'Estas aulas das tuas turmas caem em feriados ou interrupções.'
                        }}
                        <template v-if="blockedReason === 'read_only'">
                            O plano da organização só permite consultar as aulas, por isso aqui só podes vê-las.
                        </template>
                        <template v-else-if="blockedReason === 'support_session'">
                            Numa sessão de suporte não se corrigem aulas: aqui só podes consultá-las.
                        </template>
                        <template v-else>O ano letivo está fechado, por isso aqui só podes consultá-las.</template>
                    </template>
                </DialogDescription>
            </DialogHeader>

            <Alert v-if="error" variant="destructive" data-testid="regularize-error">
                <AlertDescription>
                    <p>{{ error }}</p>
                    <Button
                        v-if="retryable && !loading"
                        type="button"
                        variant="outline"
                        class="mt-2 min-h-11"
                        data-testid="regularize-retry"
                        @click="requestPreview"
                    >
                        Tentar de novo
                    </Button>
                </AlertDescription>
            </Alert>

            <p v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground" role="status">
                <Spinner /> A ver as tuas aulas…
            </p>

            <div v-if="plan && !loading" class="space-y-4 text-sm" data-testid="regularize-preview">
                <section
                    v-for="item in plan.classes"
                    :key="item.class ?? item.class_label"
                    class="space-y-3 rounded-lg border p-3"
                    :data-class="item.class_label"
                >
                    <h3 class="font-semibold">{{ item.class_label }}</h3>

                    <div v-if="item.retired > 0" class="space-y-1" data-testid="regularize-fixed">
                        <h4 class="flex items-center gap-2 font-medium">
                            <CalendarCheck class="size-4" aria-hidden="true" /> Vai ser corrigido
                        </h4>
                        <p>
                            {{ plural(item.retired, 'aula vazia é retirada', 'aulas vazias são retiradas') }}:
                            {{ item.retired === 1 ? 'não tem' : 'não têm' }} nada escrito, por isso não se perde nada.
                        </p>
                        <ul class="list-disc space-y-0.5 pl-5">
                            <li v-for="lesson in lessonsOf(item, 'A')" :key="lesson.ulid">{{ whenLabel(lesson) }}<template v-if="lesson.group"> · {{ lesson.group }}</template></li>
                        </ul>
                    </div>

                    <div v-if="item.shifted + item.awaiting_teacher > 0" class="space-y-1" data-testid="regularize-kept">
                        <h4 class="flex items-center gap-2 font-medium">
                            <ShieldCheck class="size-4" aria-hidden="true" /> O planeamento passa para a aula seguinte
                        </h4>
                        <p>
                            {{ plural(item.shifted + item.awaiting_teacher, 'aula com planeamento é retirada', 'aulas com planeamento são retiradas') }}.
                            O planeamento não se apaga: passa para a aula seguinte da mesma turma (ou do mesmo grupo).
                        </p>
                        <ul class="list-disc space-y-0.5 pl-5" data-testid="regularize-planning-retired">
                            <li v-for="(sentence, index) in planningOf(item).retired" :key="`r-${index}`">{{ sentence }}</li>
                        </ul>
                        <p
                            v-for="(sentence, index) in planningOf(item).advanced"
                            :key="`a-${index}`"
                            class="text-muted-foreground"
                            data-testid="regularize-planning-advanced"
                        >
                            {{ sentence }}
                        </p>
                    </div>

                    <div v-if="changesNumbering(item)" class="space-y-1" data-testid="regularize-numbering">
                        <h4 class="flex items-center gap-2 font-medium">
                            <ListOrdered class="size-4" aria-hidden="true" /> Atenção à numeração
                        </h4>
                        <p v-if="item.renumbered > 0" data-testid="regularize-renumbered">{{ renumberedSentence(item.renumbered) }}</p>
                        <p v-if="item.gaps.length > 0" data-testid="regularize-gaps">{{ gapsSentence(item.gaps) }}</p>
                        <p class="text-muted-foreground">{{ RENUMBERING_REASON }}</p>
                    </div>

                    <div v-if="reviewCount(item) > 0" class="space-y-1" data-testid="regularize-flagged">
                        <h4 class="font-medium">Precisa de revisão</h4>
                        <p>
                            {{ plural(reviewCount(item), 'aula não pode ser corrigida', 'aulas não podem ser corrigidas') }}
                            automaticamente e {{ reviewCount(item) === 1 ? 'fica como está' : 'ficam como estão' }}. Vê
                            {{ reviewCount(item) === 1 ? 'a razão' : 'a razão de cada uma' }} na lista de baixo.
                        </p>
                    </div>

                    <div v-if="laterCount(item) > 0" class="space-y-1" data-testid="regularize-later">
                        <h4 class="font-medium">Fica para a correção seguinte</h4>
                        <p>
                            {{ plural(laterCount(item), 'aula com planeamento fica', 'aulas com planeamento ficam') }}
                            para a correção seguinte: são demasiadas para uma só vez. Depois de confirmar, volta a
                            «Ver e corrigir» para {{ laterCount(item) === 1 ? 'a tratar' : 'as tratar' }}.
                        </p>
                    </div>
                </section>

                <p v-if="!plan.changes_anything" class="text-muted-foreground" data-testid="regularize-nothing">
                    Não há aulas a corrigir automaticamente.
                </p>
            </div>

            <section
                v-if="reviewList.length > 0 && !loading"
                class="space-y-2 rounded-lg border p-3 text-sm"
                aria-labelledby="regularize-review"
                data-testid="regularize-review"
            >
                <h3 id="regularize-review" class="font-semibold">Aulas para rever uma a uma</h3>
                <p class="text-muted-foreground">
                    Não são alteradas automaticamente — cada uma diz porquê. Abre cada uma e decide.
                </p>
                <ul class="space-y-2">
                    <li v-for="lesson in reviewList" :key="lesson.ulid" class="space-y-0.5" data-testid="regularize-review-item">
                        <a
                            :href="`/lessons/${lesson.ulid}`"
                            class="font-medium underline underline-offset-2"
                            :aria-label="`Abrir a aula de ${lessonContext(lesson)} em ${whenLabel(lesson)}`"
                        >
                            {{ whenLabel(lesson) }} · {{ lessonContext(lesson) }}
                        </a>
                        <p v-if="lesson.exception_titles.length > 0" class="text-muted-foreground">
                            {{ lesson.exception_titles.join(', ') }}
                        </p>
                        <ul v-if="lesson.reasons.length > 0" class="list-disc pl-5 text-muted-foreground">
                            <li v-for="reason in lesson.reasons" :key="reason.code">{{ reason.label }}</li>
                        </ul>
                    </li>
                </ul>
            </section>

            <!-- Rodapé fixo: os botões ficam à vista com a lista comprida. -->
            <DialogFooter class="sticky bottom-0 -mx-6 gap-2 border-t bg-background px-6 py-4" data-testid="regularize-footer">
                <Button type="button" variant="outline" class="min-h-11" data-testid="regularize-close" @click="open = false">
                    {{ showsConfirm ? 'Cancelar' : 'Fechar' }}
                </Button>
                <Button
                    v-if="showsConfirm"
                    type="button"
                    class="min-h-11"
                    data-testid="regularize-confirm"
                    :disabled="!canConfirm"
                    @click="confirm"
                >
                    <Spinner v-if="submitting" />
                    Confirmar e corrigir
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
