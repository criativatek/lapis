<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { PencilLine } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { exportNoticeText, loadRectification } from '@/lib/rectification';
import type { RectificationDetails, RectificationReasonOption, RectificationScale, RectificationState } from '@/lib/rectification';

/**
 * «Retificar» numa classificação já publicada: o botão e o diálogo.
 *
 * Partilhado por Classificações e por Resultados. SÓ DESENHA O QUE O SERVIDOR
 * DIZ: o botão aparece se `allowed`; se não é permitido mas há motivo, aparece
 * desativado com esse motivo; sem motivo, não aparece nada. Nenhuma palavra
 * sobre quem pode retificar nasce aqui.
 *
 * O controlo do novo valor é o mesmo que o ecrã usa em «Alterar»: os níveis da
 * escala, ou um número com os limites dela. Quem escreve é o servidor, pelo
 * serviço RectifyClassification; este componente só envia e mostra a recusa.
 */
const props = withDefaults(
    defineProps<{
        rectification: RectificationState;
        scale: RectificationScale;
        reasons: RectificationReasonOption[];
        /** O valor vigente: o texto a mostrar e aquilo contra que o novo se compara. */
        current: { text: string; levelId: number | null; value: string | null };
        studentName: string;
        /** `classifications.rectify`. */
        submitUrl: string;
        /** `classifications.rectification` da classificação vigente. */
        detailsUrl: string;
        scope?: string;
        /** Só o ícone, para uma célula apertada (a grelha de Resultados). */
        compact?: boolean;
    }>(),
    { scope: 'period', compact: false },
);

const open = ref(false);
const loading = ref(false);
const loadFailed = ref(false);
const details = ref<RectificationDetails | null>(null);

const levelId = ref<number | null>(null);
// Um <input type="number"> entrega um número ao v-model: lê-se sempre como texto.
const value = ref<string | number>('');
const typedValue = computed(() => String(value.value ?? '').trim());
const reason = ref<string>('');
const note = ref<string>('');
const confirmed = ref(false);
const submitting = ref(false);
const errors = ref<Record<string, string>>({});

const idPrefix = computed(() => `rectify-${props.detailsUrl.split('/').slice(-2, -1)[0] ?? 'x'}`);
const selectedReason = computed(() => props.reasons.find((option) => option.value === reason.value) ?? null);
const noteRequired = computed(() => selectedReason.value?.requires_note === true);

/** Há um valor escolhido e é outro que não o vigente. */
const valueChanged = computed(() => {
    if (props.scale.classifies_by_level) {
        return levelId.value !== null && levelId.value !== props.current.levelId;
    }

    const typed = typedValue.value;

    if (typed === '' || Number.isNaN(Number(typed))) {
        return false;
    }

    return props.current.value === null || Number(typed) !== Number(props.current.value);
});

const canSubmit = computed(
    () =>
        !submitting.value &&
        valueChanged.value &&
        reason.value !== '' &&
        (!noteRequired.value || note.value.trim() !== '') &&
        (!props.rectification.needs_confirmation || confirmed.value),
);

watch(open, async (isOpen) => {
    if (!isOpen) {
        return;
    }

    // Abre no valor vigente, em vista, e só deixa submeter quando for outro.
    levelId.value = props.current.levelId;
    value.value = props.current.value ?? '';
    reason.value = '';
    note.value = '';
    confirmed.value = false;
    errors.value = {};
    details.value = null;
    loadFailed.value = false;
    loading.value = true;

    try {
        details.value = await loadRectification(props.detailsUrl);
    } catch {
        loadFailed.value = true;
    } finally {
        loading.value = false;
    }
});

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    // Só viaja o campo em que esta escala se decide.
    router.post(
        props.submitUrl,
        {
            reason: reason.value,
            note: note.value.trim() === '' ? null : note.value.trim(),
            final_scale_level_id: props.scale.classifies_by_level ? levelId.value : null,
            final_value: props.scale.classifies_by_level ? null : typedValue.value,
            lock_version: props.rectification.lock_version,
            closed_confirmed: props.rectification.needs_confirmation && confirmed.value,
            scope: props.scope,
        },
        {
            preserveScroll: true,
            onStart: () => (submitting.value = true),
            onFinish: () => (submitting.value = false),
            onSuccess: () => {
                open.value = false;
            },
            onError: (received: Record<string, string>) => {
                errors.value = received;
            },
        },
    );
}

const valueError = computed(() => errors.value.final_value ?? errors.value.final_scale_level_id);
</script>

<template>
    <button
        v-if="rectification.allowed"
        type="button"
        class="rounded-md border border-border text-xs hover:bg-muted/40"
        :class="compact ? 'p-1.5' : 'px-2.5 py-2'"
        :title="compact ? 'Retificar classificação' : undefined"
        data-testid="rectify-button"
        @click="open = true"
    >
        <PencilLine class="inline size-3" :class="{ 'mr-1': !compact }" /><span :class="{ 'sr-only': compact }">Retificar</span>
    </button>
    <span v-else-if="rectification.blocked_reason" class="inline-flex flex-col items-end gap-0.5">
        <button
            type="button"
            class="cursor-not-allowed rounded-md border border-border text-xs opacity-50"
            :class="compact ? 'p-1.5' : 'px-2.5 py-2'"
            disabled
            :title="rectification.blocked_reason"
            data-testid="rectify-button"
        >
            <PencilLine class="inline size-3" :class="{ 'mr-1': !compact }" /><span :class="{ 'sr-only': compact }">Retificar</span>
        </button>
        <span
            class="max-w-56 text-right text-xs text-muted-foreground"
            :class="{ 'sr-only': compact }"
            data-testid="rectify-blocked-reason"
        >{{ rectification.blocked_reason }}</span>
    </span>

    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Retificar classificação</DialogTitle>
                <DialogDescription>{{ studentName }}</DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <p class="text-sm">
                    <span class="text-muted-foreground">Valor atual: </span>
                    <strong class="tabular-nums" data-testid="rectify-current">{{ current.text }}</strong>
                </p>

                <div class="space-y-1">
                    <label :for="`${idPrefix}-value`" class="block text-sm font-medium">{{ scale.label }} (novo)</label>
                    <!-- O mesmo controlo de «Alterar»: lista fechada nos níveis,
                         número com os limites da escala nos intervalos. -->
                    <select
                        v-if="scale.classifies_by_level"
                        :id="`${idPrefix}-value`"
                        v-model="levelId"
                        class="w-full rounded-md border border-border bg-background px-2 py-1.5 text-sm"
                        data-testid="rectify-level"
                    >
                        <option v-for="level in scale.levels" :key="level.id" :value="level.id">{{ level.code }} — {{ level.label }}</option>
                    </select>
                    <input
                        v-else
                        :id="`${idPrefix}-value`"
                        v-model="value"
                        type="number"
                        step="0.001"
                        :min="scale.min_value ?? undefined"
                        :max="scale.max_value ?? undefined"
                        class="w-32 rounded-md border border-border bg-background px-2 py-1.5 text-sm tabular-nums"
                        data-testid="rectify-number"
                    />
                    <InputError :message="valueError" />
                </div>

                <div class="space-y-1">
                    <label :for="`${idPrefix}-reason`" class="block text-sm font-medium">Motivo</label>
                    <select
                        :id="`${idPrefix}-reason`"
                        v-model="reason"
                        class="w-full rounded-md border border-border bg-background px-2 py-1.5 text-sm"
                        data-testid="rectify-reason"
                    >
                        <option value="" disabled>Escolha o motivo…</option>
                        <option v-for="option in reasons" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <InputError :message="errors.reason" />
                </div>

                <div class="space-y-1">
                    <label :for="`${idPrefix}-note`" class="block text-sm font-medium">
                        Nota <span class="font-normal text-muted-foreground">{{ noteRequired ? '(obrigatória)' : '(opcional)' }}</span>
                    </label>
                    <textarea
                        :id="`${idPrefix}-note`"
                        v-model="note"
                        rows="3"
                        maxlength="1000"
                        :required="noteRequired"
                        class="w-full rounded-md border border-border bg-background px-2 py-1.5 text-sm"
                        data-testid="rectify-note"
                    />
                    <InputError :message="errors.note" />
                </div>

                <div class="space-y-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    <p v-if="loading">A verificar se esta classificação foi comunicada…</p>
                    <p v-else-if="loadFailed" role="alert">Não foi possível carregar os avisos desta classificação.</p>
                    <template v-else-if="details">
                        <p data-testid="rectify-notice-generic">{{ details.notice.generic }}</p>
                        <p v-if="details.notice.export" class="font-medium" data-testid="rectify-notice-export">
                            {{ exportNoticeText(details.notice.export) }}
                        </p>
                    </template>
                </div>

                <p v-if="scope === 'period'" class="text-xs text-muted-foreground" data-testid="rectify-accumulated">
                    A classificação acumulada não é alterada automaticamente.
                </p>

                <label v-if="rectification.needs_confirmation" class="flex items-start gap-2 text-sm" data-testid="rectify-confirmation">
                    <input v-model="confirmed" type="checkbox" class="mt-0.5 size-4" data-testid="rectify-confirm-box" />
                    <span>O período está encerrado. Confirmo que quero alterar uma classificação já publicada.</span>
                </label>

                <InputError :message="errors.rectify" />

                <DialogFooter>
                    <button
                        type="button"
                        class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                        data-testid="rectify-cancel"
                        @click="open = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        class="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                        :disabled="!canSubmit"
                        data-testid="rectify-submit"
                    >
                        Retificar
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
