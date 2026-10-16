<script setup lang="ts">
/**
 * CONFIRMAR A MUDANÇA DE NUMERAÇÃO (0.169.0) — no próprio editor.
 *
 * Aparece quando a pré-visualização do servidor mostra que gravar este número
 * muda o de outras aulas JÁ REGISTADAS (lecionadas ou com sumário), ou que há
 * avisos (uma aula vizinha com número incoerente, uma aula que fica sem
 * número). Nada disto bloqueia: o professor decide, a lista diz exatamente o que
 * muda, e «Guardar» envia a confirmação que o servidor deu — se a numeração
 * mudar entretanto, o servidor recusa e a pré-visualização refaz-se.
 */
import { TriangleAlert } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { effectLine } from '@/lib/lessonNumber';
import type { LessonNumberPreview } from '@/lib/lessonNumber';

defineProps<{
    preview: LessonNumberPreview;
    saving: boolean;
    idPrefix: string;
}>();

defineEmits<{ confirm: []; cancel: [] }>();
</script>

<template>
    <section
        :aria-labelledby="`${idPrefix}-title`"
        class="grid gap-3 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
        data-testid="lesson-number-confirmation"
    >
        <h3 :id="`${idPrefix}-title`" class="flex items-start gap-1.5 font-semibold">
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            Esta alteração muda a numeração de outras aulas
        </h3>

        <ul v-if="preview.effects.length > 0" class="grid gap-1 tabular-nums" data-testid="lesson-number-effects">
            <li v-for="effect in preview.effects" :key="effect.ulid">
                {{ effectLine(effect) }}
                <span v-if="effect.recorded" class="text-xs font-semibold">(já registada)</span>
            </li>
        </ul>

        <ul v-if="preview.warnings.length > 0" class="grid gap-1" data-testid="lesson-number-warnings">
            <li v-for="warning in preview.warnings" :key="warning">{{ warning }}</li>
        </ul>

        <div class="flex flex-wrap gap-2">
            <Button
                type="button"
                class="min-h-11 flex-1 sm:min-h-9 sm:flex-none"
                :disabled="saving"
                data-testid="lesson-number-confirm"
                @click="$emit('confirm')"
            >
                <Spinner v-if="saving" />
                Guardar
            </Button>
            <Button
                type="button"
                variant="outline"
                class="min-h-11 flex-1 sm:min-h-9 sm:flex-none"
                :disabled="saving"
                data-testid="lesson-number-cancel"
                @click="$emit('cancel')"
            >
                Cancelar
            </Button>
        </div>
    </section>
</template>
