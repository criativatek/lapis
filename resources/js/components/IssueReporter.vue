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
import { clientContext  } from '@/lib/diagnostics';
import type {ClientContext} from '@/lib/diagnostics';
import { currentMaskedRoute } from '@/lib/routeMask';

/**
 * Reportar um problema sem sair de onde se está.
 *
 * O QUE ISTO RESOLVE. A Central de Suporte já existia e está no menu, mas
 * obrigava a sair do ecrã, ir a um formulário e descrever por palavras onde a
 * pessoa estava. Quem encontra um defeito raramente o volta a encontrar depois
 * de navegar para outro lado — e a informação mais útil para o reproduzir é
 * justamente a que se perde nesse caminho.
 *
 * É O MESMO PEDIDO, NÃO UM SISTEMA PARALELO. Publica em `POST /support`, cai na
 * mesma fila, no mesmo fio de conversa e no mesmo relógio de retenção. O que
 * muda é só de onde se abre.
 *
 * A ROTA VAI MASCARADA, E O SERVIDOR MASCARA-A OUTRA VEZ. `maskRoute()` existe
 * para quem envia ver o que envia; a regra é imposta em
 * `App\Support\Support\RouteMask`, porque o código que decide o que se remove
 * não pode ser o que viaja no browser de quem envia.
 */

const page = usePage();

const categories = computed(
    () => (page.props.supportCategories ?? []) as { value: string; label: string }[],
);

/** Só para quem tem sessão: um convidado não tem fila onde acompanhar isto. */
const authenticated = computed(() => Boolean((page.props.auth as { user?: unknown } | undefined)?.user));

const open = ref(false);

/**
 * Declarado em vez de inferido: com os campos anuláveis todos juntos, a
 * inferência do `useForm` desiste e devolve `unknown`, e depois é o template
 * inteiro que deixa de ser verificado.
 */
type IssueForm = {
    category: string;
    subject: string;
    description: string;
    technical_route: string | null;
    technical_reference: string | null;
    client_context: ClientContext | null;
};

const form = useForm<IssueForm>({
    category: '',
    subject: '',
    description: '',
    technical_route: null,
    technical_reference: null,
    client_context: null,
});

// A rota é lida no momento em que a janela abre, não quando o componente monta:
// o layout monta uma vez e a pessoa navega por dentro dele.
watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.technical_route = currentMaskedRoute();
        // `page.component` é um literal escrito no repositório — «classes/Show»
        // — e não uma rota: diz em que ecrã a pessoa estava sem dizer sobre quem.
        form.client_context = clientContext(page.component ?? null);
    }
});

/** O que o aviso mostra: a mesma coisa que vai ser enviada, por extenso. */
const browserLabel = computed(() => {
    const detected = form.client_context?.environment;

    if (!detected?.browser || detected.browser === 'unknown') {
        return null;
    }

    return [detected.browser, detected.browser_major].filter(Boolean).join(' ');
});

/** As contas que o aviso mostra: o que segue, contado. */
const counts = computed(() => ({
    console: form.client_context?.console?.length ?? 0,
    network: form.client_context?.network?.length ?? 0,
    errors: form.client_context?.errors?.length ?? 0,
}));

function submit(): void {
    form.post('/support', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
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

                <form class="space-y-4" @submit.prevent="submit">
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
                            rows="5"
                            maxlength="5000"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            required
                        ></textarea>
                        <InputError :message="form.errors.description" />
                    </div>

                    <!--
                        O QUE SEGUE É DITO POR EXTENSO, E COM AS CONTAS À VISTA.
                        Um aviso genérico não deixa ninguém decidir nada, e um
                        aviso desactualizado é pior do que nenhum: se algum dia
                        passar a seguir mais alguma coisa, esta lista tem de
                        crescer no mesmo commit.
                    -->
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
