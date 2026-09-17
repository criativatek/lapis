<script setup lang="ts">
/**
 * Fecho rápido de uma aula a partir da semana (0.147.0).
 *
 * O SISTEMA SUGERE, O PROFESSOR CONFIRMA. Este componente só aparece para aulas
 * abertas que já começaram (lessonQuickCloseState) e cada ação é um clique
 * explícito — nada é marcado por ter passado a hora.
 *
 * «✓ Lecionada» usa o MESMO endpoint da página da aula (MarkLessonAsTaught):
 * sem rascunho de faltas, fica «Sem faltas»; com rascunho, consolida-o. As
 * outras duas ações não escrevem nada aqui — pedem à página que abra o único
 * diálogo de resultado (LessonOutcomeDialog).
 *
 * FORA DO <Link> do cartão, sempre: um botão dentro de uma ligação navegaria ao
 * ser ativado pelo teclado.
 *
 * Duas apresentações: `desktop` (botão discreto + menu «•••», a partir de sm)
 * e `mobile` (um só botão de 44px que abre um menu com itens de 44px). A
 * `responsive` mostra cada uma no seu tamanho de ecrã.
 */
import { router } from '@inertiajs/vue3';
import { Check, Ellipsis } from '@lucide/vue';
import { ref } from 'vue';
import type { SpecialLessonOutcome } from '@/components/lessons/LessonOutcomeDialog.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const props = withDefaults(
    defineProps<{
        lessonUlid: string;
        contextLabel: string;
        time: string;
        variant?: 'responsive' | 'desktop' | 'mobile';
    }>(),
    { variant: 'responsive' },
);

const emit = defineEmits<{ outcome: [SpecialLessonOutcome] }>();

const processing = ref(false);

function markTaught(): void {
    if (processing.value) {
        return;
    }

    processing.value = true;
    router.post(
        `/lessons/${props.lessonUlid}/mark-taught`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="flex items-center gap-1">
        <!-- Ecrã largo: visível sempre, com pouca ênfase; mais forte ao passar
             o rato ou com foco dentro do cartão. Nunca só no hover. -->
        <div
            v-if="variant !== 'mobile'"
            :class="['items-center gap-1', variant === 'responsive' ? 'hidden sm:flex' : 'flex']"
            data-testid="lesson-quick-actions-desktop"
        >
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="h-7 gap-1 px-2 text-xs opacity-80 group-hover:opacity-100 group-focus-within:opacity-100 hover:opacity-100 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring"
                :disabled="processing"
                :aria-label="`Marcar a aula de ${contextLabel} (${time}) como lecionada`"
                data-testid="quick-mark-taught"
                @click="markTaught"
            >
                <Check class="size-3.5" aria-hidden="true" /> Lecionada
            </Button>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-7 w-7 p-0 opacity-80 group-hover:opacity-100 group-focus-within:opacity-100 hover:opacity-100 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring"
                        :aria-label="`Mais ações para a aula de ${contextLabel}`"
                        data-testid="quick-more-actions"
                    >
                        <Ellipsis class="size-4" aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem data-testid="quick-teacher-absent" @select="emit('outcome', 'teacher_absent')">
                        Professor ausente
                    </DropdownMenuItem>
                    <DropdownMenuItem data-testid="quick-external-activity" @select="emit('outcome', 'class_external_activity')">
                        Turma em outras atividades letivas
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <!-- Telemóvel: um só alvo de 44px, e as três ações num menu com itens
             do mesmo tamanho — nada de botões minúsculos lado a lado. -->
        <DropdownMenu v-if="variant !== 'desktop'">
            <DropdownMenuTrigger as-child>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :class="['min-h-11 w-full', variant === 'responsive' ? 'sm:hidden' : '']"
                    :disabled="processing"
                    :aria-label="`Fechar a aula de ${contextLabel} (${time})`"
                    data-testid="quick-close-mobile"
                >
                    Fechar aula
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="min-w-[16rem]">
                <DropdownMenuItem class="min-h-11" data-testid="quick-mark-taught-mobile" @select="markTaught">
                    <Check class="size-4" aria-hidden="true" /> Lecionada
                </DropdownMenuItem>
                <DropdownMenuItem class="min-h-11" @select="emit('outcome', 'teacher_absent')">
                    Professor ausente
                </DropdownMenuItem>
                <DropdownMenuItem class="min-h-11" @select="emit('outcome', 'class_external_activity')">
                    Turma em outras atividades letivas
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
