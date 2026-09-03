<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { clientContext } from '@/lib/diagnostics';
import type { ClientContext } from '@/lib/diagnostics';
import { dictationMode, dictationNotice, startDictation } from '@/lib/dictation';
import type { DictationMode, DictationSession } from '@/lib/dictation';
import { currentMaskedRoute } from '@/lib/routeMask';
import { capture } from '@/lib/screenshot';
import type { Screenshot } from '@/lib/screenshot';

/**
 * Reportar um problema sem sair de onde se está.
 *
 * É O MESMO PEDIDO, NÃO UM SISTEMA PARALELO. Cai na mesma fila, no mesmo fio de
 * conversa e no mesmo relógio de retenção. O que muda é de onde se abre e o que
 * o acompanha.
 *
 * A CAPTURA ACONTECE NO CLIQUE DE «REPORTAR PROBLEMA» — antes de existir
 * diálogo. Foi decidido assim no reporte SUP-2B5T3J (2026-09-03), e resolve
 * pela raiz o defeito que esse reporte trouxe: capturar com o diálogo aberto
 * apanhava o véu dele (`data-slot="dialog-overlay"`, um irmão do conteúdo que o
 * esconder de `[role="dialog"]` não tocava) e a imagem saía toda escurecida.
 * No clique ainda não há véu nenhum. A captura automática usa a via SILENCIOSA
 * (redesenho do DOM) — a via com autorização do browser fica para o «Repetir
 * captura», onde o gesto é explícito.
 *
 * Capturar automaticamente NÃO é enviar automaticamente. A imagem fica no
 * browser, à vista, e só segue certificada:
 *
 *   1. vê-se antes de seguir, e amplia-se a 1:1, porque uma miniatura dentro de
 *      um diálogo não se revê;
 *   2. a certificação é uma caixa PRÓPRIA, por baixo da imagem;
 *   3. sem essa marca a IMAGEM não segue — e o reporte segue na mesma. Bloquear
 *      o envio inteiro ensinaria a marcar sem olhar, que é o contrário do que a
 *      caixa existe para fazer;
 *   4. quando a aplicação reconhece dados pessoais no texto da página, o aviso é
 *      concreto — «esta página mostra nomes» — e não uma advertência genérica.
 *      A aplicação desenhou aquela página: sabe o que lá está.
 *
 * SEM ASSUNTO NEM RESUMO. O mesmo reporte pediu-o: quem descreve um defeito já
 * o está a resumir, e a rota, o componente e o contexto dizem o resto. O
 * servidor deriva o resumo da primeira linha da descrição
 * (`StoreIssueReportRequest::payload()`); a categoria nasce «other» e a triagem
 * do backoffice reclassifica quando fizer diferença.
 *
 * A regra do ponto 3 é imposta no SERVIDOR (`OpenIssueReport`). Isto é como a
 * pessoa a exerce, não onde ela vive.
 *
 * O DITADO É OUTRA COISA, e não leva certificação: o que sai dele é texto que
 * fica no ecrã, à frente de quem falou, editável antes de seguir. Leva **aviso**,
 * que é o que o caso pede. Hoje nenhum browser transcreve português contínuo no
 * dispositivo (medido — ver `lib/dictation.ts`), portanto a voz vai para o
 * serviço do fornecedor do browser, e quem vai falar tem de o saber **antes** de
 * falar: «quando abro a turma da Ana Martins dá erro» é a frase natural de quem
 * reporta, não a excepção. O aviso muda com o modo, e o modo é verificado a cada
 * abertura — no dia em que houver modelo local, o texto passa a dizer isso sem
 * ninguém mexer aqui.
 */

const page = usePage();

const authenticated = computed(() => Boolean((page.props.auth as { user?: unknown } | undefined)?.user));

const open = ref(false);
const capturing = ref(false);
const screenshot = ref<Screenshot | null>(null);
const certified = ref(false);
const zoomed = ref(false);

const dictation = ref<DictationMode>('none');
const listening = ref<DictationSession | null>(null);
const dictationError = ref<string | null>(null);
const notice = computed(() => dictationNotice(dictation.value));

type IssueForm = {
    description: string;
    technical_route: string | null;
    client_context: ClientContext | null;
    screenshot: File | null;
    screenshot_certified: boolean;
    screenshot_warning: string | null;
    images: File[];
};

const form = useForm<IssueForm>({
    description: '',
    technical_route: null,
    client_context: null,
    screenshot: null,
    screenshot_certified: false,
    screenshot_warning: null,
    images: [],
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.technical_route = currentMaskedRoute();
        form.client_context = clientContext(page.component ?? null);

        // A sonda é feita ao abrir, e não ao carregar a aplicação: perguntar por
        // um modelo de voz em todas as páginas para um diálogo que quase nunca
        // se abre é trabalho a mais em todos os ecrãs do produto.
        void probeDictation();

        return;
    }

    stopListening();
    discardScreenshot();
});

const counts = computed(() => ({
    console: form.client_context?.console?.length ?? 0,
    network: form.client_context?.network?.length ?? 0,
    errors: form.client_context?.errors?.length ?? 0,
}));

const browserLabel = computed(() => {
    const detected = form.client_context?.environment;

    if (!detected?.browser || detected.browser === 'unknown') {
        return null;
    }

    return [detected.browser, detected.browser_major].filter(Boolean).join(' ');
});

async function probeDictation(): Promise<void> {
    dictation.value = await dictationMode();
}

function toggleDictation(): void {
    if (listening.value) {
        stopListening();

        return;
    }

    if (dictation.value === 'none') {
        return;
    }

    dictationError.value = null;

    // O que já estava escrito não se perde nem se mistura: o ditado começa numa
    // linha nova a partir do que lá está, e quem dita continua a poder corrigir
    // à mão depois de parar.
    const existing = form.description.trimEnd();

    listening.value = startDictation(dictation.value, {
        onText: (text) => {
            form.description = existing ? `${existing}\n${text}` : text;
        },
        onError: (error) => {
            // Os três que acontecem de verdade. `no-speech` é o mais comum de
            // todos — chega-se ao fim de uma pausa e o browser desiste — e
            // mostrá-lo pelo nome técnico faz parecer defeito o que foi silêncio.
            dictationError.value = {
                'not-allowed': 'O browser não deu acesso ao microfone.',
                'no-speech': 'Não ouvi nada. Carregue em «Ditar» e fale.',
                network: 'Sem ligação para transcrever. Escreva, ou tente mais tarde.',
            }[error] ?? `Não foi possível transcrever (${error}).`;
            listening.value = null;
        },
        onEnd: () => {
            listening.value = null;
        },
    });

    if (!listening.value) {
        dictationError.value = 'Não foi possível começar a ouvir.';
    }
}

function stopListening(): void {
    listening.value?.stop();
    listening.value = null;
}

/**
 * O clique em «Reportar problema»: captura primeiro, abre depois.
 *
 * Nesta ordem não há véu de diálogo para apanhar — o defeito do SUP-2B5T3J
 * era exactamente capturar com ele à frente. Via silenciosa: um clique para
 * reportar não é um clique para responder ao pedido de partilha de ecrã do
 * browser. Se a captura falhar, o diálogo abre na mesma, sem imagem.
 */
async function openReporter(): Promise<void> {
    if (capturing.value) {
        return;
    }

    capturing.value = true;
    discardScreenshot();

    try {
        const hide = Array.from(document.querySelectorAll<HTMLElement>('[data-issue-launcher]'));

        screenshot.value = await capture(hide, { silent: true });
        form.screenshot_warning = screenshot.value?.warning ?? null;
    } finally {
        capturing.value = false;
        open.value = true;
    }
}

/**
 * «Repetir captura», já com o diálogo aberto — o gesto explícito, com a via
 * completa (autorização e imagem verdadeira do ecrã incluídas).
 */
async function takeScreenshot(): Promise<void> {
    capturing.value = true;
    discardScreenshot();

    try {
        // O diálogo, O SEU VÉU e o botão escondem-se durante a captura. O véu
        // (`data-slot="dialog-overlay"`) é um irmão do conteúdo, não um filho:
        // esconder só `[role="dialog"]` deixava-o na imagem e saía tudo
        // escurecido a 50% — o defeito reportado no SUP-2B5T3J.
        const hide = Array.from(
            document.querySelectorAll<HTMLElement>(
                '[role="dialog"], [data-slot="dialog-overlay"], [data-issue-launcher]',
            ),
        );

        screenshot.value = await capture(hide);
        form.screenshot_warning = screenshot.value?.warning ?? null;
    } finally {
        capturing.value = false;
    }
}

function discardScreenshot(): void {
    if (screenshot.value) {
        URL.revokeObjectURL(screenshot.value.url);
    }

    screenshot.value = null;
    certified.value = false;
    zoomed.value = false;
    form.screenshot = null;
    form.screenshot_certified = false;
    form.screenshot_warning = null;
}

function pickImages(event: Event): void {
    form.images = Array.from((event.target as HTMLInputElement).files ?? []).slice(0, 3);
}

function submit(): void {
    // Um microfone que continua ligado depois de o diálogo fechar é uma luz
    // acesa que ninguém pediu.
    stopListening();

    // Só vai o que foi certificado. O servidor impõe a mesma regra; isto evita
    // enviar bytes que vão ser deitados fora do outro lado.
    form.screenshot = certified.value ? (screenshot.value?.file ?? null) : null;
    form.screenshot_certified = certified.value;

    form.post('/issues', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            discardScreenshot();
            open.value = false;
        },
    });
}
</script>

<template>
    <div v-if="authenticated">
        <Button
            type="button"
            variant="secondary"
            size="sm"
            data-issue-launcher
            class="fixed bottom-4 right-4 z-40 shadow-lg print:hidden"
            :disabled="capturing"
            @click="openReporter"
        >
            {{ capturing ? 'A capturar…' : 'Reportar problema' }}
        </Button>

        <Dialog v-model:open="open">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader class="space-y-2">
                    <DialogTitle>Reportar um problema</DialogTitle>
                    <DialogDescription>
                        Descreva o que aconteceu. O pedido entra na sua área de Suporte, onde pode acompanhar a resposta.
                    </DialogDescription>
                </DialogHeader>

                <form class="max-h-[70vh] space-y-4 overflow-y-auto" @submit.prevent="submit">
                    <!--
                        Sem «Assunto» nem «Resumo» — pedido no SUP-2B5T3J. A
                        descrição já é o resumo; o servidor deriva o resto.
                    -->
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between gap-3">
                            <label class="text-sm font-medium" for="issue-description">O que aconteceu</label>

                            <Button
                                v-if="dictation !== 'none'"
                                type="button"
                                :variant="listening ? 'destructive' : 'secondary'"
                                size="sm"
                                data-issue-dictate
                                @click="toggleDictation"
                            >
                                {{ listening ? 'Parar de ouvir' : 'Ditar' }}
                            </Button>
                        </div>

                        <textarea
                            id="issue-description"
                            v-model="form.description"
                            rows="4"
                            maxlength="5000"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            required
                        ></textarea>

                        <!--
                            O AVISO APARECE ANTES DE HAVER VOZ, e não só enquanto
                            o microfone está ligado. Quem só o vê depois de falar
                            já falou — e é essa a única decisão que este texto
                            existe para permitir.
                        -->
                        <p v-if="notice" class="text-xs text-muted-foreground" data-issue-dictation-notice>
                            <template v-if="listening">A ouvir. </template>{{ notice }}
                        </p>

                        <p v-if="dictationError" class="text-xs text-destructive">{{ dictationError }}</p>

                        <InputError :message="form.errors.description" />
                    </div>

                    <!--
                        A captura foi tirada no clique do botão e está aqui à
                        vista. Só SEGUE se for certificada — capturar não é
                        enviar, e a regra vive no servidor.
                    -->
                    <div class="space-y-2 rounded-md border border-border p-3">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-medium">Captura de ecrã</span>
                            <div class="flex gap-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    :disabled="capturing"
                                    @click="takeScreenshot"
                                >
                                    {{ capturing ? 'A capturar…' : screenshot ? 'Repetir captura' : 'Juntar captura' }}
                                </Button>
                                <Button v-if="screenshot" type="button" variant="ghost" size="sm" @click="discardScreenshot">
                                    Remover
                                </Button>
                            </div>
                        </div>

                        <p v-if="!screenshot" class="text-xs text-muted-foreground">
                            Sem captura. Nada do seu ecrã segue com o reporte.
                        </p>

                        <template v-else>
                            <div class="max-h-64 overflow-auto rounded border border-border bg-muted">
                                <img
                                    :src="screenshot.url"
                                    alt="Captura do seu ecrã"
                                    :class="zoomed ? 'max-w-none' : 'w-full'"
                                    @click="zoomed = !zoomed"
                                />
                            </div>
                            <button type="button" class="text-xs underline" @click="zoomed = !zoomed">
                                {{ zoomed ? 'Reduzir' : 'Ver em tamanho real' }}
                            </button>

                            <p
                                v-if="screenshot.warning"
                                class="rounded bg-amber-50 px-2 py-1.5 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-100"
                            >
                                {{ screenshot.warning }} Reveja a imagem antes de a enviar.
                            </p>

                            <label class="flex items-start gap-2 text-xs">
                                <input v-model="certified" type="checkbox" class="mt-0.5" />
                                <span>Revi esta imagem e confirmo que não mostra dados sensíveis de alunos.</span>
                            </label>
                            <p v-if="!certified" class="text-xs text-muted-foreground">
                                Sem esta confirmação a imagem não segue — o reporte segue na mesma.
                            </p>
                        </template>
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-sm font-medium" for="issue-images">Outras imagens (opcional)</label>
                        <input
                            id="issue-images"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            multiple
                            class="w-full text-xs"
                            @change="pickImages"
                        />
                        <InputError :message="form.errors.images" />
                    </div>

                    <div class="space-y-1 rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                        <p>Segue também, sem ação sua:</p>
                        <ul class="list-inside list-disc space-y-0.5">
                            <li>
                                o ecrã onde está —
                                <code class="font-mono">{{ form.technical_route ?? 'não identificado' }}</code>
                            </li>
                            <li>a versão da aplicação<template v-if="browserLabel"> e o seu browser ({{ browserLabel }})</template></li>
                            <li v-if="counts.errors">{{ counts.errors }} erro(s) técnico(s) do seu browser</li>
                            <li v-if="counts.network">{{ counts.network }} pedido(s) ao servidor, com o endereço do ecrã e o resultado</li>
                            <li v-if="counts.console">{{ counts.console }} mensagem(ns) técnica(s) da consola do browser</li>
                        </ul>
                        <p class="pt-1">Por favor, não escreva nomes de alunos.</p>
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary">Cancelar</Button>
                        </DialogClose>
                        <Button type="submit" :disabled="form.processing">Enviar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
