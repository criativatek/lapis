<script setup lang="ts">
/**
 * O SUMÁRIO FOI ALTERADO NOUTRA JANELA — comparar e combinar, sem perder nada.
 *
 * Aparece quando o servidor recusa uma gravação porque a versão que o ecrã leu
 * já não é a gravada. O rascunho do professor nunca sai do editor: aqui só se
 * mostram os dois textos lado a lado, com as linhas que só existem num deles
 * assinaladas, e se oferece COMBINAR (a ação principal). Ficar só com o próprio
 * texto é possível, mas secundário; descartar o rascunho é a última opção e
 * pede confirmação. Qualquer que seja a escolha, a gravação seguinte volta a
 * verificar a versão no servidor.
 */
import { CircleAlert, Combine } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { compareLines } from '@/lib/summaryMerge';

const props = defineProps<{ draft: string; stored: string }>();
const emit = defineEmits<{ combine: []; 'keep-mine': []; 'use-stored': [] }>();

const mine = computed(() => compareLines(props.draft, props.stored));
const theirs = computed(() => compareLines(props.stored, props.draft));

const root = ref<HTMLElement | null>(null);

defineExpose({ focus: () => root.value?.focus() });
</script>

<template>
    <section
        ref="root"
        tabindex="-1"
        role="alert"
        aria-labelledby="summary-conflict-title"
        data-testid="summary-conflict"
        class="grid gap-3 rounded-[10px] border border-destructive/40 bg-destructive/5 p-4 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
    >
        <p
            id="summary-conflict-title"
            class="flex items-start gap-2 font-semibold text-destructive"
        >
            <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            O sumário desta aula foi alterado noutra janela depois de começares
            a editar.
        </p>
        <p class="text-sm text-foreground">
            O teu texto não foi gravado e continua no editor. Compara os dois e
            combina-os antes de guardar; ao guardar, a versão volta a ser
            verificada.
        </p>
        <div class="grid gap-3 @2xl:grid-cols-2">
            <figure
                class="grid content-start gap-1.5 rounded-lg border bg-card p-3"
            >
                <figcaption class="text-xs font-semibold text-muted-foreground">
                    O teu texto, por gravar
                </figcaption>
                <p
                    v-for="(line, index) in mine"
                    :key="`mine-${index}`"
                    :class="[
                        'min-h-5 text-sm leading-6 whitespace-pre-wrap',
                        line.onlyHere
                            ? 'border-l-2 border-primary pl-2 font-medium'
                            : 'pl-[10px]',
                    ]"
                >
                    <span v-if="line.onlyHere" class="sr-only"
                        >Só no teu texto: </span
                    >{{ line.text }}
                </p>
            </figure>
            <figure
                class="grid content-start gap-1.5 rounded-lg border border-dashed bg-card p-3"
            >
                <figcaption class="text-xs font-semibold text-muted-foreground">
                    Gravado agora
                </figcaption>
                <p
                    v-for="(line, index) in theirs"
                    :key="`theirs-${index}`"
                    :class="[
                        'min-h-5 text-sm leading-6 whitespace-pre-wrap',
                        line.onlyHere
                            ? 'border-l-2 border-dashed border-foreground/60 pl-2 font-medium'
                            : 'pl-[10px]',
                    ]"
                >
                    <span v-if="line.onlyHere" class="sr-only"
                        >Só no texto gravado: </span
                    >{{ line.text }}
                </p>
                <p
                    v-if="theirs.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    O sumário gravado está vazio.
                </p>
            </figure>
        </div>
        <p class="text-xs text-muted-foreground">
            As linhas com traço à esquerda só existem num dos textos.
        </p>
        <div class="flex flex-wrap gap-2">
            <Button
                type="button"
                class="min-h-11 sm:min-h-9"
                data-testid="conflict-combine"
                @click="emit('combine')"
            >
                <Combine class="size-4" aria-hidden="true" /> Combinar no editor
            </Button>
            <Button
                type="button"
                variant="outline"
                class="min-h-11 sm:min-h-9"
                data-testid="conflict-keep-mine"
                @click="emit('keep-mine')"
            >
                Continuar só com o meu texto
            </Button>
            <Button
                type="button"
                variant="ghost"
                class="min-h-11 text-muted-foreground sm:min-h-9"
                data-testid="conflict-use-stored"
                @click="emit('use-stored')"
            >
                Descartar o meu e usar o gravado
            </Button>
        </div>
    </section>
</template>
