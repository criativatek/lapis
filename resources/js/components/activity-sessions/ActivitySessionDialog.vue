<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { CalendarClock, History } from '@lucide/vue';
import { computed, watch } from 'vue';
import ActivitySessionStatePill from '@/components/activity-sessions/ActivitySessionStatePill.vue';
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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    activitySessionDateLabel,
    activitySessionNumberLabel,
} from '@/lib/activitySessions';
import type {
    ActivitySessionAction,
    ActivitySessionItem,
} from '@/lib/activitySessions';
import { capitalizeFirst } from '@/lib/text';

/**
 * O REGISTO DE UMA SESSÃO de uma «outra atividade» (0.170.0): o sumário do
 * apoio, da reunião ou do clube numa data. Usado no Horário e em Aulas e
 * Sumários — o mesmo diálogo, o mesmo pedido.
 *
 * É propositadamente simples: um sumário e quatro ações. Sem notas privadas nem
 * campos pedagógicos. A sessão só passa a existir quando o professor guarda;
 * abrir este diálogo não escreve nada.
 *
 * Em acesso técnico (ADR-0014) o servidor recusa a escrita; aqui os botões
 * ficam desativados para não prometer o que não se pode fazer.
 */
const props = defineProps<{
    open: boolean;
    session: ActivitySessionItem | null;
    /** O bloco ainda existe no horário e pode ser editado a partir daqui. */
    canEditSchedule?: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    'edit-schedule': [];
}>();

const page = usePage();
const impersonating = computed(() => Boolean(page.props.impersonating));

const form = useForm<{
    summary: string;
    action: ActivitySessionAction;
    activity_ulid: string;
    occurs_on: string;
}>({ summary: '', action: 'save', activity_ulid: '', occurs_on: '' });

watch(
    () => [props.open, props.session?.key] as const,
    ([isOpen]) => {
        if (!isOpen || props.session === null) {
            return;
        }

        form.clearErrors();
        form.summary = props.session.summary ?? '';
        form.activity_ulid = props.session.activity_ulid ?? '';
        form.occurs_on = props.session.occurs_on;
    },
    { immediate: true },
);

const exists = computed(() => props.session?.session_ulid != null);
const numberLabel = computed(() =>
    props.session ? activitySessionNumberLabel(props.session) : null,
);
const blocked = computed(
    () =>
        form.processing ||
        impersonating.value ||
        (!exists.value && props.session?.registrable === false),
);
// Um registo vazio não regista nada: sem texto, «Guardar» só faz sentido numa
// sessão que já existe (para a esvaziar).
const canSaveText = computed(() => exists.value || form.summary.trim() !== '');

const description = computed(() => {
    const session = props.session;

    if (session === null) {
        return '';
    }

    return [
        capitalizeFirst(activitySessionDateLabel(session.occurs_on)),
        `${session.start_time}–${session.end_time}`,
        session.kind_label,
        session.school_class?.label ?? null,
    ]
        .filter((part) => part !== null)
        .join(' · ');
});

const historyHref = computed(() => {
    const session = props.session;

    if (session === null) {
        return '/activity-sessions';
    }

    const activity = session.activity_ulid ?? `title:${session.title}`;

    return `/activity-sessions?activity=${encodeURIComponent(activity)}`;
});

function close(): void {
    emit('update:open', false);
}

function submit(action: ActivitySessionAction): void {
    const session = props.session;

    if (session === null || blocked.value) {
        return;
    }

    form.action = action;
    const options = { preserveScroll: true, onSuccess: close };

    if (session.session_ulid !== null) {
        form.transform(({ summary, action: chosen }) => ({
            summary,
            action: chosen,
        })).put(`/activity-sessions/${session.session_ulid}`, options);

        return;
    }

    form.transform((data) => data).post('/activity-sessions', options);
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            class="max-h-[90vh] max-w-xl overflow-y-auto"
            data-testid="activity-session-dialog"
        >
            <DialogHeader v-if="session">
                <DialogTitle class="wrap-anywhere">{{
                    session.title
                }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <div v-if="session" class="space-y-4">
                <div class="flex flex-wrap items-center gap-2">
                    <ActivitySessionStatePill
                        :presentation="session.presentation"
                        :label="session.presentation_label"
                    />
                    <span
                        v-if="numberLabel"
                        class="text-sm font-medium"
                        data-testid="activity-session-number"
                        >{{ numberLabel }}</span
                    >
                </div>

                <p
                    v-if="!exists && !session.registrable"
                    class="rounded-md border border-dashed p-3 text-sm text-muted-foreground"
                    data-testid="activity-session-non-teaching"
                >
                    Este dia é não letivo no calendário: não se regista uma
                    sessão.
                </p>
                <p
                    v-if="exists && !session.follows_schedule"
                    class="rounded-md border border-dashed p-3 text-sm text-muted-foreground"
                >
                    Esta sessão ficou na data e hora em que foi registada. O
                    horário da atividade mudou depois.
                </p>
                <p
                    v-if="impersonating"
                    class="rounded-md border border-dashed p-3 text-sm text-muted-foreground"
                >
                    Em acesso técnico só se pode consultar: o registo é do
                    professor.
                </p>

                <div class="grid gap-1.5">
                    <Label for="activity-session-summary">Sumário</Label>
                    <Textarea
                        id="activity-session-summary"
                        v-model="form.summary"
                        data-testid="activity-session-summary"
                        class="min-h-40 wrap-anywhere"
                        maxlength="16000"
                        placeholder="O que se fez ou vai fazer nesta sessão."
                        :disabled="impersonating"
                    />
                    <InputError :message="form.errors.summary" />
                    <InputError :message="form.errors.occurs_on" />
                    <InputError :message="form.errors.activity_ulid" />
                    <InputError :message="form.errors.action" />
                </div>

                <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm">
                    <Link
                        :href="historyHref"
                        class="inline-flex items-center gap-1.5 font-medium text-primary underline-offset-4 hover:underline"
                        data-testid="activity-session-history-link"
                    >
                        <History class="size-4" aria-hidden="true" /> Ver
                        histórico desta atividade
                    </Link>
                    <button
                        v-if="canEditSchedule"
                        type="button"
                        class="inline-flex items-center gap-1.5 font-medium text-muted-foreground underline-offset-4 hover:underline"
                        data-testid="activity-session-edit-schedule"
                        @click="emit('edit-schedule')"
                    >
                        <CalendarClock class="size-4" aria-hidden="true" />
                        Editar horário
                    </button>
                </div>
            </div>

            <DialogFooter v-if="session" class="gap-2 sm:justify-between">
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="blocked"
                        data-testid="activity-session-not-held"
                        @click="submit('not_held')"
                        >Não se realizou</Button
                    >
                    <Button
                        v-if="session.status !== 'planned'"
                        type="button"
                        variant="outline"
                        :disabled="blocked"
                        data-testid="activity-session-reopen"
                        @click="submit('reopen')"
                        >Voltar a por realizar</Button
                    >
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="blocked || !canSaveText"
                        data-testid="activity-session-save"
                        @click="submit('save')"
                        >{{
                            form.processing ? 'A guardar…' : 'Guardar'
                        }}</Button
                    >
                    <Button
                        v-if="session.status !== 'done'"
                        type="button"
                        :disabled="blocked"
                        data-testid="activity-session-done"
                        @click="submit('done')"
                        >Guardar e marcar como realizada</Button
                    >
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
