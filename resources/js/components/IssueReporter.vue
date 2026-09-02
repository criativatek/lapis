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
 * A CAPTURA DE ECRÃ ENTRA DESMARCADA, e sai de novo com um clique. Uma captura
 * do Lapispro é, por construção, uma imagem dos dados sobre que o defeito é —
 * uma tabela de nomes de crianças contra classificações — e ninguém deve enviar
 * uma sem a ter visto. Por isso:
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
 * A regra do ponto 3 é imposta no SERVIDOR (`OpenIssueReport`). Isto é como a
 * pessoa a exerce, não onde ela vive.
 */

const page = usePage();

const categories = computed(() => (page.props.supportCategories ?? []) as { value: string; label: string }[]);
const authenticated = computed(() => Boolean((page.props.auth as { user?: unknown } | undefined)?.user));

const open = ref(false);
const capturing = ref(false);
const screenshot = ref<Screenshot | null>(null);
const certified = ref(false);
const zoomed = ref(false);

type IssueForm = {
    category: string;
    subject: string;
    description: string;
    technical_route: string | null;
    client_context: ClientContext | null;
    screenshot: File | null;
    screenshot_certified: boolean;
    screenshot_warning: string | null;
    images: File[];
};

const form = useForm<IssueForm>({
    category: '',
    subject: '',
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

        return;
    }

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

async function takeScreenshot(): Promise<void> {
    capturing.value = true;
    discardScreenshot();

    try {
        // O diálogo e o botão escondem-se durante a captura: uma imagem com o
        // próprio formulário lá dentro não mostra defeito nenhum.
        const hide = Array.from(
            document.querySelectorAll<HTMLElement>('[role="dialog"], [data-issue-launcher]'),
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
            @click="open = true"
        >
            Reportar problema
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
                    <div class="space-y-1.5">
                        <label class="text-sm font-medium" for="issue-category">Assunto</label>
                        <select
                            id="issue-category"
                            v-model="form.category"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            required
                        >
                            <option value="" disabled>Escolha um assunto</option>
                            <option v-for="category in categories" :key="category.value" :value="category.value">
                                {{ category.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.category" />
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-sm font-medium" for="issue-subject">Resumo</label>
                        <input
                            id="issue-subject"
                            v-model="form.subject"
                            type="text"
                            maxlength="200"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            required
                        />
                        <InputError :message="form.errors.subject" />
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-sm font-medium" for="issue-description">O que aconteceu</label>
                        <textarea
                            id="issue-description"
                            v-model="form.description"
                            rows="4"
                            maxlength="5000"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            required
                        ></textarea>
                        <InputError :message="form.errors.description" />
                    </div>

                    <!-- Captura de ecrã: desmarcada por omissão, sempre. -->
                    <div class="space-y-2 rounded-md border border-border p-3">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-medium">Captura de ecrã</span>
                            <Button
                                v-if="!screenshot"
                                type="button"
                                variant="secondary"
                                size="sm"
                                :disabled="capturing"
                                @click="takeScreenshot"
                            >
                                {{ capturing ? 'A capturar…' : 'Juntar captura' }}
                            </Button>
                            <Button v-else type="button" variant="ghost" size="sm" @click="discardScreenshot">
                                Remover
                            </Button>
                        </div>

                        <p v-if="!screenshot" class="text-xs text-muted-foreground">
                            Opcional. Nada é capturado sem carregar aqui.
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
