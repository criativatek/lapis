<script setup lang="ts">
/**
 * «N.º DA LIÇÃO» (0.169.0) — o número que o Lapispro sugere e o professor pode
 * ajustar.
 *
 * 0.172.0: UMA AULA, UM NÚMERO. A opção «esta aula corresponde a mais do que
 * uma lição» deixou de existir; o campo é só o número. Uma aula antiga que
 * ficou registada como «Lições 12–13» continua a mostrá-lo (`original.legacyEnd`,
 * só leitura) com um aviso discreto; editar o número apaga esse fim.
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
    { canSet: true, frozen: false, errors: () => ({ number: null }), disabled: false },
);

const emit = defineEmits<{
    'update:modelValue': [value: LessonNumberDraft];
}>();

const startId = computed(() => `${props.idPrefix}-start`);
const helpId = computed(() => `${props.idPrefix}-help`);
const errorId = computed(() => `${props.idPrefix}-error`);
const legacyId = computed(() => `${props.idPrefix}-legacy`);

/** O número que a aula tem, dito como no resto da aplicação («11» ou «11–12»). */
const currentLabel = computed(() => {
    if (props.original.start === '') {
        return 'sem número';
    }

    return props.original.legacyEnd === null ? props.original.start : `${props.original.start}–${props.original.legacyEnd}`;
});

const dirty = computed(() => numberDraftDirty(props.modelValue, props.original));
const hadNoNumber = computed(() => props.original.start === '' && !props.manual);

/** Só uma linha antiga (intervalo gravado até à 0.171.0) e ainda por editar. */
const showLegacy = computed(
    () => props.original.legacyEnd !== null && props.modelValue.legacyEnd !== null && !props.modelValue.automatic,
);

/** Sugerida pelo servidor (não indicada pelo professor) e com número. */
const suggested = computed(() => !props.manual && props.original.start !== '');

const startDescribedBy = computed(
    () => `${helpId.value}${showLegacy.value ? ` ${legacyId.value}` : ''}${props.errors.number ? ` ${errorId.value}` : ''}`,
);

function patch(change: Partial<LessonNumberDraft>): void {
    emit('update:modelValue', { ...props.modelValue, ...change });
}

function onStart(event: Event): void {
    // Qualquer edição do número apaga o fim legado: a aula passa a uma só lição.
    patch({ start: (event.target as HTMLInputElement).value, legacyEnd: null });
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

            <input
                v-else
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

            <p
                v-if="showLegacy"
                :id="legacyId"
                class="text-sm text-muted-foreground"
                data-testid="lesson-number-legacy-range"
            >
                Esta aula ficou registada como Lições {{ original.start }}–{{ original.legacyEnd }}, uma opção que deixou de existir. Ao editar o número, a aula passa a corresponder a uma só lição.
            </p>

            <p
                v-if="errors.number"
                :id="errorId"
                role="alert"
                class="flex items-start gap-1.5 text-sm font-semibold text-destructive"
                data-testid="lesson-number-error"
            >
                <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ errors.number }}
            </p>
            <p :id="helpId" class="text-xs text-muted-foreground">
                {{ suggested ? 'Sugerido automaticamente. Pode alterar se necessário.' : 'Pode alterar se necessário.' }}
            </p>
        </template>
    </div>
</template>
