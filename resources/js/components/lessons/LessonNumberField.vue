<script setup lang="ts">
/**
 * «N.º DA LIÇÃO» (0.169.0) — o número que o Lapispro sugere e o professor pode
 * ajustar.
 *
 * Componente CONTROLADO: o rascunho (`modelValue`) e o ponto de partida
 * (`original`) pertencem ao editor que o usa — a página da aula e o cartão da
 * semana —, porque é o editor que decide quando pedir a pré-visualização e
 * que chaves juntar à gravação. Aqui só se edita, se explica e se mostra o que
 * está mal. O que é «mudou» está em `lib/lessonNumber` e é o mesmo nos dois.
 *
 * Aula com professor ausente não tem lição: não há campo, só a frase.
 *
 * NUMERAÇÃO CONGELADA (contenção da recuperação, `LAPIS_LESSON_NUMBERING_FROZEN`):
 * o número aparece só de leitura, com a frase que explica que o sumário se
 * guarda na mesma. O servidor recusa qualquer alteração de qualquer maneira.
 */
import { CircleAlert, RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { numberDraftDirty, numberSavePayload } from '@/lib/lessonNumber';
import type { LessonNumberDraft, LessonNumberErrors } from '@/lib/lessonNumber';

const props = withDefaults(
    defineProps<{
        modelValue: LessonNumberDraft;
        /** O que a aula tinha quando o editor abriu — o rascunho compara-se com isto. */
        original: LessonNumberDraft;
        /** O número foi indicado pelo professor (e não é recalculado). */
        manual: boolean;
        /** Falso numa aula de professor ausente. */
        canSet?: boolean;
        /** A numeração está congelada: o número só se lê. */
        frozen?: boolean;
        errors?: LessonNumberErrors;
        /** Prefixo dos ids: dois editores abertos na mesma página não partilham ids. */
        idPrefix: string;
        disabled?: boolean;
    }>(),
    { canSet: true, frozen: false, errors: () => ({ number: null, end: null }), disabled: false },
);

const emit = defineEmits<{
    'update:modelValue': [value: LessonNumberDraft];
}>();

const startId = computed(() => `${props.idPrefix}-start`);
const endId = computed(() => `${props.idPrefix}-end`);
const helpId = computed(() => `${props.idPrefix}-help`);
const errorId = computed(() => `${props.idPrefix}-error`);
const endErrorId = computed(() => `${props.idPrefix}-end-error`);
const multipleId = computed(() => `${props.idPrefix}-multiple`);

/** O número que a aula tem, dito como no resto da aplicação («11» ou «11–12»). */
const currentLabel = computed(() => {
    if (props.original.start === '') {
        return 'sem número';
    }

    return props.original.end === '' ? props.original.start : `${props.original.start}–${props.original.end}`;
});

const dirty = computed(() => numberDraftDirty(props.modelValue, props.original));
const hadNoNumber = computed(() => props.original.start === '' && !props.manual);

const startDescribedBy = computed(() => `${helpId.value}${props.errors.number ? ` ${errorId.value}` : ''}`);
const endDescribedBy = computed(() => `${helpId.value}${props.errors.end ? ` ${endErrorId.value}` : ''}`);

function patch(change: Partial<LessonNumberDraft>): void {
    emit('update:modelValue', { ...props.modelValue, ...change });
}

function onStart(event: Event): void {
    patch({ start: (event.target as HTMLInputElement).value });
}

function onEnd(event: Event): void {
    patch({ end: (event.target as HTMLInputElement).value });
}

function onMultiple(event: Event): void {
    const checked = (event.target as HTMLInputElement).checked;

    // Desmarcar esconde o segundo input e esquece-o: o que não se vê não se envia.
    patch({ multiple: checked, end: checked ? props.modelValue.end : '' });
}

function resetToAutomatic(): void {
    patch({ automatic: true });
}

function keepIndicated(): void {
    emit('update:modelValue', { ...props.original });
}

defineExpose({
    dirty,
    /** As chaves `lesson_number*` do pedido de gravação; só faz sentido com `dirty`. */
    payload: (confirmation: string | null = null) => numberSavePayload(props.modelValue, confirmation),
});
</script>

<template>
    <div class="grid gap-2" data-testid="lesson-number-field">
        <p
            v-if="!canSet"
            class="text-sm text-muted-foreground"
            data-testid="lesson-number-absent"
        >
            Professor ausente: esta aula não tem número de lição.
        </p>

        <div
            v-else-if="frozen"
            class="grid gap-1"
            data-testid="lesson-number-frozen"
        >
            <span class="text-sm font-semibold">N.º da lição</span>
            <p class="text-base tabular-nums" data-testid="lesson-number-frozen-value">
                {{ currentLabel }}
            </p>
            <p class="text-sm text-muted-foreground">
                A numeração das lições está temporariamente bloqueada. Pode guardar o sumário sem alterar o número.
            </p>
        </div>

        <template v-else>
            <label :for="modelValue.automatic ? undefined : startId" class="text-sm font-semibold">N.º da lição</label>

            <p
                v-if="manual && !modelValue.automatic"
                class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm"
                data-testid="lesson-number-manual"
            >
                <span>Número indicado manualmente.</span>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="min-h-11 sm:min-h-8"
                    :disabled="disabled"
                    data-testid="lesson-number-reset"
                    @click="resetToAutomatic"
                >
                    <RotateCcw class="size-4" aria-hidden="true" />
                    Repor numeração automática
                </Button>
            </p>

            <p
                v-if="hadNoNumber && !modelValue.automatic"
                class="text-sm text-muted-foreground"
                data-testid="lesson-number-none"
            >
                Esta aula ainda não tem número: não há um número livre entre as aulas vizinhas. Pode indicá-lo.
            </p>

            <div
                v-if="modelValue.automatic"
                class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm"
                role="status"
                data-testid="lesson-number-automatic"
            >
                <span>Ao guardar, volta a numeração automática.</span>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="min-h-11 sm:min-h-8"
                    :disabled="disabled"
                    data-testid="lesson-number-keep"
                    @click="keepIndicated"
                >
                    Manter o número indicado
                </Button>
            </div>

            <template v-else>
                <div class="flex flex-wrap items-end gap-x-4 gap-y-2">
                    <input
                        :id="startId"
                        :value="modelValue.start"
                        type="number"
                        inputmode="numeric"
                        min="1"
                        max="9999"
                        step="1"
                        :disabled="disabled"
                        :aria-invalid="errors.number ? true : undefined"
                        :aria-describedby="startDescribedBy"
                        class="h-11 w-28 rounded-[10px] border border-input bg-background px-3 text-base tabular-nums shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive"
                        data-testid="lesson-number-start"
                        @input="onStart"
                    >

                    <label
                        :for="multipleId"
                        class="flex min-h-11 items-center gap-2 text-sm"
                    >
                        <input
                            :id="multipleId"
                            type="checkbox"
                            :checked="modelValue.multiple"
                            :disabled="disabled"
                            class="size-5 shrink-0 accent-primary"
                            data-testid="lesson-number-multiple"
                            @change="onMultiple"
                        >
                        Esta aula corresponde a mais do que uma lição
                    </label>
                </div>

                <div v-if="modelValue.multiple" class="flex flex-wrap items-center gap-2">
                    <label :for="endId" class="text-sm font-semibold">até à lição</label>
                    <input
                        :id="endId"
                        :value="modelValue.end"
                        type="number"
                        inputmode="numeric"
                        min="1"
                        max="9999"
                        step="1"
                        :disabled="disabled"
                        :aria-invalid="errors.end ? true : undefined"
                        :aria-describedby="endDescribedBy"
                        class="h-11 w-28 rounded-[10px] border border-input bg-background px-3 text-base tabular-nums shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive"
                        data-testid="lesson-number-end"
                        @input="onEnd"
                    >
                </div>
            </template>

            <p
                v-if="errors.number"
                :id="errorId"
                role="alert"
                class="flex items-start gap-1.5 text-sm font-semibold text-destructive"
                data-testid="lesson-number-error"
            >
                <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ errors.number }}
            </p>
            <p
                v-if="errors.end"
                :id="endErrorId"
                role="alert"
                class="flex items-start gap-1.5 text-sm font-semibold text-destructive"
                data-testid="lesson-number-end-error"
            >
                <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ errors.end }}
            </p>

            <p :id="helpId" class="text-xs text-muted-foreground">
                A numeração é sugerida automaticamente. Pode ajustá-la se começou a utilizar o Lapispro a meio do ano ou precisar de acertar a sequência.
            </p>
        </template>
    </div>
</template>
