<script setup lang="ts">
/**
 * PROJETAR O SUMÁRIO NA SALA (0.159.0) — só leitura, só o que é dos alunos.
 *
 * Um ecrã inteiro do viewport, com a turma, a disciplina, a data, a lição e o
 * sumário completo em letra grande. O que mostra vem TODO de `LessonProjection`
 * (lib/lessonProjection.ts), a lista branca: este componente nunca recebe a
 * aula, por isso não tem como mostrar notas, assiduidade, estado ou nomes.
 *
 * Abrir, ampliar e fechar não gravam nada e não pedem nada à rede. A página por
 * baixo fica exatamente onde estava: o diálogo do reka-ui trava o scroll e
 * prende o foco, e ao fechar o foco volta ao botão que abriu — o reka só sabe
 * fazê-lo para um DialogTrigger, e aqui a abertura vem de fora, por isso o
 * elemento é guardado quando a projeção abre e devolvido no `close-auto-focus`.
 *
 * Fechar de propósito: clicar fora NÃO fecha (numa sala, um toque perdido não
 * pode tirar o sumário do quadro). Fecha-se pelo botão ou por Escape, e o
 * Escape respeita primeiro o ecrã inteiro: o que sai do ecrã inteiro não fecha
 * a projeção. Os browsers consomem eles próprios o primeiro Escape do ecrã
 * inteiro, por isso uma pequena janela depois da saída também o ignora.
 *
 * A letra é fluida pela largura do ecrã e multiplicada por uma escala que o
 * professor escolhe (A−/A+, ou as teclas − e +) e o browser lembra. O texto
 * nunca é cortado: a área de leitura rola, e é focável para as setas e o
 * PageDown a deslocarem.
 */
import { AArrowDown, AArrowUp, Maximize, Minimize, X } from '@lucide/vue';
import {
    DialogContent,
    DialogDescription,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import type { LessonProjection } from '@/lib/lessonProjection';
import { summaryParagraphs } from '@/lib/lessons';

const props = defineProps<{
    open: boolean;
    projection: LessonProjection | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const SCALES = [0.7, 0.85, 1, 1.15, 1.3, 1.5, 1.75, 2] as const;
const DEFAULT_INDEX = SCALES.indexOf(1);
const SCALE_KEY = 'lapis.lessons.projectionScale';
// O Escape que o próprio browser usou para sair do ecrã inteiro ainda chega ao
// documento; dentro desta janela, não conta como um pedido para fechar.
const FULLSCREEN_ESCAPE_GRACE_MS = 300;

function readScaleIndex(): number {
    try {
        const stored = Number(window.localStorage.getItem(SCALE_KEY));
        const index = SCALES.findIndex((scale) => scale === stored);

        return index === -1 ? DEFAULT_INDEX : index;
    } catch {
        return DEFAULT_INDEX;
    }
}

const scaleIndex = ref(readScaleIndex());
const scale = computed(() => SCALES[scaleIndex.value] ?? 1);
const scalePercent = computed(() => `${Math.round(scale.value * 100)}%`);
const canDecrease = computed(() => scaleIndex.value > 0);
const canIncrease = computed(() => scaleIndex.value < SCALES.length - 1);

function setScaleIndex(index: number): void {
    scaleIndex.value = Math.min(Math.max(index, 0), SCALES.length - 1);

    try {
        window.localStorage.setItem(SCALE_KEY, String(scale.value));
    } catch {
        // Sem armazenamento, a escala vale só para esta projeção.
    }
}

const root = ref<HTMLElement | null>(null);
const reader = ref<HTMLElement | null>(null);
const fullscreen = ref(false);
const fullscreenSupported = ref(false);
let opener: HTMLElement | null = null;
let lastFullscreenExit = 0;

const isOpen = computed(() => props.open && props.projection !== null);
const context = computed(() =>
    props.projection
        ? [
              props.projection.classLabel,
              props.projection.subject,
              props.projection.groupLabel,
          ]
              .filter((part): part is string => !!part)
              .join(' · ')
        : '',
);
const paragraphs = computed(() =>
    summaryParagraphs(props.projection?.summary ?? ''),
);

// Síncrono, para apanhar o foco ANTES de o conteúdo do diálogo montar e o roubar.
watch(
    isOpen,
    (open) => {
        if (open) {
            opener =
                document.activeElement instanceof HTMLElement &&
                document.activeElement !== document.body
                    ? document.activeElement
                    : null;
            fullscreenSupported.value =
                document.fullscreenEnabled === true &&
                typeof document.documentElement.requestFullscreen ===
                    'function';
        } else {
            leaveFullscreen();
        }
    },
    { flush: 'sync', immediate: true },
);

function leaveFullscreen(): void {
    if (document.fullscreenElement) {
        void Promise.resolve(document.exitFullscreen()).catch(() => undefined);
    }
}

function toggleFullscreen(): void {
    if (document.fullscreenElement) {
        leaveFullscreen();

        return;
    }

    void Promise.resolve(root.value?.requestFullscreen()).catch(
        () => undefined,
    );
}

function onFullscreenChange(): void {
    const active = document.fullscreenElement !== null;

    if (fullscreen.value && !active) {
        lastFullscreenExit = Date.now();
    }

    fullscreen.value = active;
}

// Fechar em ecrã inteiro: sair primeiro, e só depois desmontar. Se o elemento
// em ecrã inteiro desaparece antes de o browser acabar de sair, o Chromium
// devolve o foco ao <body> e o professor perde o cartão de onde partiu.
async function close(): Promise<void> {
    if (document.fullscreenElement) {
        try {
            await document.exitFullscreen();
        } catch {
            // Sem ecrã inteiro para sair: fecha na mesma.
        }
    }

    emit('update:open', false);
}

function onEscape(event: KeyboardEvent): void {
    if (document.fullscreenElement) {
        event.preventDefault();
        leaveFullscreen();

        return;
    }

    if (Date.now() - lastFullscreenExit < FULLSCREEN_ESCAPE_GRACE_MS) {
        event.preventDefault();
    }
}

function onOpenAutoFocus(event: Event): void {
    event.preventDefault();
    reader.value?.focus({ preventScroll: true });
}

function onCloseAutoFocus(event: Event): void {
    event.preventDefault();

    if (opener?.isConnected) {
        opener.focus({ preventScroll: true });
    }

    opener = null;
}

function keep(event: Event): void {
    // Clicar ou focar fora não fecha a projeção.
    event.preventDefault();
}

function onKeydown(event: KeyboardEvent): void {
    if (!isOpen.value || event.ctrlKey || event.metaKey || event.altKey) {
        return;
    }

    if (event.key === '+' || event.key === '=') {
        event.preventDefault();
        setScaleIndex(scaleIndex.value + 1);
    } else if (event.key === '-') {
        event.preventDefault();
        setScaleIndex(scaleIndex.value - 1);
    }
}

onMounted(() => {
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('fullscreenchange', onFullscreenChange);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    leaveFullscreen();
});
</script>

<template>
    <DialogRoot :open="isOpen" @update:open="(value) => !value && close()">
        <DialogPortal>
            <DialogContent
                class="fixed inset-0 z-50 outline-none motion-safe:duration-150 motion-safe:data-[state=open]:animate-in motion-safe:data-[state=open]:fade-in-0"
                data-testid="lesson-projection"
                @escape-key-down="onEscape"
                @open-auto-focus="onOpenAutoFocus"
                @close-auto-focus="onCloseAutoFocus"
                @pointer-down-outside="keep"
                @interact-outside="keep"
            >
                <div
                    ref="root"
                    class="flex h-full w-full flex-col bg-background text-foreground"
                    :style="{ '--projection-scale': scale }"
                >
                    <!-- BARRA — só os controlos do professor, discreta e fixa; a leitura rola por baixo. -->
                    <div
                        class="flex flex-wrap items-center justify-end gap-x-4 gap-y-1 border-b px-4 py-2 sm:px-6"
                    >
                        <DialogDescription class="sr-only">
                            Apresentação do sumário da aula, só de leitura.
                            Escape fecha.
                        </DialogDescription>

                        <div
                            class="flex flex-wrap items-center gap-1"
                            role="group"
                            aria-label="Controlos da projeção"
                        >
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-11 sm:size-9"
                                aria-label="Diminuir a letra"
                                data-testid="projection-smaller"
                                :disabled="!canDecrease"
                                @click="setScaleIndex(scaleIndex - 1)"
                            >
                                <AArrowDown aria-hidden="true" />
                            </Button>
                            <span
                                class="min-w-12 text-center text-sm font-semibold tabular-nums"
                                aria-live="polite"
                                data-testid="projection-scale"
                                >{{ scalePercent }}</span
                            >
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-11 sm:size-9"
                                aria-label="Aumentar a letra"
                                data-testid="projection-larger"
                                :disabled="!canIncrease"
                                @click="setScaleIndex(scaleIndex + 1)"
                            >
                                <AArrowUp aria-hidden="true" />
                            </Button>
                            <Button
                                v-if="fullscreenSupported"
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-11 sm:size-9"
                                :aria-label="
                                    fullscreen
                                        ? 'Sair do ecrã inteiro'
                                        : 'Ecrã inteiro'
                                "
                                data-testid="projection-fullscreen"
                                @click="toggleFullscreen"
                            >
                                <Minimize
                                    v-if="fullscreen"
                                    aria-hidden="true"
                                />
                                <Maximize v-else aria-hidden="true" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-11 sm:size-9"
                                aria-label="Fechar"
                                data-testid="projection-close"
                                @click="close"
                            >
                                <X aria-hidden="true" />
                            </Button>
                        </div>
                    </div>

                    <!-- LEITURA — o contentor que rola; o texto nunca é cortado. -->
                    <div
                        ref="reader"
                        tabindex="0"
                        class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-6 outline-none focus-visible:ring-2 focus-visible:ring-ring/35 focus-visible:ring-inset sm:px-10 sm:py-10"
                        aria-label="Sumário da aula — usa as setas para deslocar"
                        data-testid="projection-reading"
                        :style="{
                            fontSize:
                                'calc(clamp(1.375rem, 0.875rem + 2.2vw, 2.75rem) * var(--projection-scale))',
                        }"
                    >
                        <article
                            v-if="projection"
                            class="mx-auto w-full max-w-[38em] leading-[1.45]"
                        >
                            <!-- O contexto é para a sala ler: na letra da projeção, não na barra. -->
                            <header
                                class="mb-[0.9em] grid gap-[0.35em] border-b pb-[0.6em]"
                            >
                                <DialogTitle
                                    as="h2"
                                    class="text-[0.75em] leading-snug font-semibold"
                                    data-testid="projection-context"
                                >
                                    <span class="sr-only"
                                        >Sumário projetado: </span
                                    >{{ context }}
                                </DialogTitle>
                                <div
                                    class="flex flex-wrap items-baseline gap-x-[0.8em] gap-y-[0.4em]"
                                >
                                    <p
                                        v-if="projection.lessonNumber !== null"
                                        class="text-[1.25em] leading-tight font-bold tracking-tight tabular-nums"
                                        data-testid="projection-number"
                                    >
                                        Lição {{ projection.lessonNumber }}
                                    </p>
                                    <p
                                        class="text-[0.7em]"
                                        data-testid="projection-date"
                                    >
                                        {{ projection.dateLabel }}
                                    </p>
                                    <!-- O mesmo azul das «Alterações por guardar» do editor: o âmbar da casa nunca é aviso. -->
                                    <p
                                        v-if="projection.unsaved"
                                        class="inline-flex items-center gap-[0.4em] self-center rounded-full border-2 border-primary bg-primary/10 px-[0.8em] py-[0.15em] text-[0.55em] font-bold text-primary"
                                        data-testid="projection-unsaved"
                                    >
                                        <span
                                            class="size-[0.55em] rounded-full bg-current"
                                            aria-hidden="true"
                                        />Por guardar<span class="sr-only"
                                            >: este texto ainda não foi
                                            guardado</span
                                        >
                                    </p>
                                </div>
                            </header>

                            <div
                                v-if="paragraphs.length > 0"
                                class="grid gap-[0.7em]"
                                data-testid="projection-summary"
                            >
                                <p
                                    v-for="(paragraph, index) in paragraphs"
                                    :key="index"
                                    class="min-w-0 wrap-anywhere whitespace-pre-line"
                                >
                                    {{ paragraph }}
                                </p>
                            </div>
                            <p
                                v-else
                                class="text-[0.8em] text-muted-foreground"
                                data-testid="projection-empty"
                            >
                                Esta aula ainda não tem sumário.
                            </p>
                        </article>
                    </div>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
