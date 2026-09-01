<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Admin → Inteligência Artificial.
 *
 * THERE IS NO FIELD ON THIS PAGE THAT DISPLAYS THE CREDENTIAL, and there is no
 * endpoint it could ask for one. What it knows is `credential_set`, when it was
 * stored, and its last four characters — everything the server is willing to
 * say. The button says «Substituir credencial», because that is the only thing
 * anybody can do to a secret they cannot read.
 *
 * THE STATE BANNER IS THE SERVER'S ANSWER, NOT THE FORM'S. «IA ativa» is
 * `status.available`, computed by AiTextProviders — the same answer every
 * teacher's screen gets — so ticking «Ativar» without a credential shows the
 * engine still unavailable and says why, instead of a green light nothing else
 * agrees with.
 */

/**
 * Every configurable ceiling, keyed by capability — plus the reserved
 * `ai_pool` entry, which is not a capability but a ceiling across all of them.
 * `user_monthly` is only meaningful under that key.
 */
type Quotas = Record<
    string,
    { user_daily: number | null; organization_monthly: number | null; user_monthly: number | null }
>;

/** The reserved key `AiQuota::POOL_LIMIT_KEY` uses inside the same map. */
const POOL_KEY = 'ai_pool';

type Capability = {
    value: string;
    label: string;
    where: string;
    metered: boolean;
    /** The plans that include it, in commercial order. Read from the database, never transcribed. */
    plans: string[];
};

type Totals = {
    calls: number;
    succeeded: number;
    failed: number;
    blocked: number;
    billable: number;
    total_tokens: number;
};

type Usage = {
    since: string;
    totals: Totals;
    by_capability: (Totals & { capability: string; label: string })[];
    by_use_case: (Totals & { use_case: string; label: string })[];
    blocked_reasons: { reason: string; label: string; count: number }[];
};

type Settings = {
    ai_enabled: boolean;
    ai_provider: string | null;
    ai_model: string | null;
    ai_timeout_seconds: number | null;
    ai_max_output_tokens: number | null;
    ai_per_minute: number | null;
    ai_organization_per_minute: number | null;
    ai_quotas: Quotas;
    credential_set: boolean;
    credential_hint: string | null;
    credential_set_at: string | null;
};

type Status = {
    available: boolean;
    unavailable_reason: string | null;
    effective: {
        driver: string | null;
        model: string | null;
        timeout: number;
        max_output_tokens: number;
        max_output_tokens_ceiling: number;
        per_minute: number;
        organization_per_minute: number;
    };
};

const props = defineProps<{
    settings: Settings;
    status: Status;
    providers: { value: string; label: string }[];
    capabilities: Capability[];
    usage: Usage;
}>();

/** Only the capabilities that can actually reach an engine get quota fields. */
const meteredCapabilities = computed(() => props.capabilities.filter((capability) => capability.metered));

const monthLabel = computed(() =>
    new Date(props.usage.since).toLocaleDateString('pt-PT', { month: 'long', year: 'numeric' }),
);

/**
 * «Base · Pro · Institucional», or an honest sentence when nothing grants it.
 *
 * A CAPABILITY IN NO PLAN IS A REAL SITUATION AND IS SAID PLAINLY. It happens
 * when a key has been catalogued but not yet composed into the offer, and the
 * consequence — no organization can reach it, so no request ever leaves — is
 * worth one sentence on the screen rather than a debugging session.
 */
function planLabel(capability: Capability): string {
    return capability.plans.length === 0 ? 'Nenhum plano inclui esta funcionalidade' : capability.plans.join(' · ');
}

const form = useForm({
    ai_enabled: props.settings.ai_enabled,
    ai_provider: props.settings.ai_provider ?? '',
    ai_model: props.settings.ai_model ?? '',
    ai_timeout_seconds: props.settings.ai_timeout_seconds,
    ai_max_output_tokens: props.settings.ai_max_output_tokens,
    ai_per_minute: props.settings.ai_per_minute,
    ai_organization_per_minute: props.settings.ai_organization_per_minute,
    ai_quotas: JSON.parse(JSON.stringify(props.settings.ai_quotas)) as Quotas,
});

// Its own form, so the credential never shares a submit — or a set of flashed
// old-input values — with an ordinary settings change.
const credentialForm = useForm({ ai_credential: '' });

const replacing = ref(!props.settings.credential_set);

const reasons: Record<string, string> = {
    off: 'A IA está desativada nesta instalação.',
    credential_missing: 'Falta a credencial do fornecedor.',
    model_missing: 'Falta indicar o modelo.',
    endpoint_missing: 'Falta indicar o endpoint.',
    unknown_driver: 'O fornecedor configurado não existe nesta instalação.',
    fake_in_production: 'O fornecedor simulado não pode ser usado em produção.',
};

const stateMessage = computed(() =>
    props.status.available
        ? 'IA ativa. Os pedidos são atendidos por este motor.'
        : (reasons[props.status.unavailable_reason ?? 'off'] ?? 'A IA não está disponível.'),
);

const credentialLabel = computed(() => {
    if (!props.settings.credential_set) {
        return 'Não configurada';
    }

    return props.settings.credential_hint
        ? `Configurada (••••${props.settings.credential_hint})`
        : 'Configurada';
});

const credentialDate = computed(() =>
    props.settings.credential_set_at
        ? new Date(props.settings.credential_set_at).toLocaleDateString('pt-PT', { dateStyle: 'long' })
        : null,
);

function save(): void {
    form.put('/admin/ai', { preserveScroll: true });
}

function saveCredential(): void {
    credentialForm.post('/admin/ai/credential', {
        preserveScroll: true,
        onSuccess: () => {
            credentialForm.reset('ai_credential');
            replacing.value = false;
        },
    });
}

function removeCredential(): void {
    router.delete('/admin/ai/credential', { preserveScroll: true });
}

function testConnection(): void {
    router.post('/admin/ai/test', {}, { preserveScroll: true });
}

/**
 * The second, dearer test — see `AiCapabilityProbe` on the server.
 *
 * «Testar ligação» asks for the word «OK» and proves a credential. This asks the
 * configured model to do the actual work — the real síntese instruction over a
 * synthetic record, and a six-section answer the real parser accepts — because a
 * model can pass the first and fail every request the product makes.
 */
function probeCapability(): void {
    router.post('/admin/ai/probe', {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Inteligência Artificial — Backoffice" />

    <div class="mx-auto w-full max-w-2xl space-y-5 p-6">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Inteligência Artificial</h1>
            <!-- Plain language, not implementation language. The old sentence
                 read «Estas definições sobrepõem-se ao .env», which is true and
                 means nothing to somebody who has never opened one. -->
            <p class="text-sm text-muted-foreground">
                O motor que atende as funcionalidades assistidas por IA. Estas definições têm prioridade sobre a
                configuração técnica do servidor.
            </p>
        </div>

        <!-- The truth, from the server, before any of the fields below. -->
        <div
            class="rounded-lg border p-4 text-sm"
            :class="status.available ? 'border-emerald-600/40 bg-emerald-500/5' : 'border-amber-600/40 bg-amber-500/5'"
            data-test="ai-state"
        >
            <div class="font-medium">{{ stateMessage }}</div>
            <div class="mt-1 text-xs text-muted-foreground">
                Em vigor: fornecedor <strong>{{ status.effective.driver ?? '—' }}</strong>,
                modelo <strong>{{ status.effective.model ?? '—' }}</strong>,
                timeout <strong>{{ status.effective.timeout }}s</strong>,
                predefinição de <strong>{{ status.effective.max_output_tokens }}</strong> tokens de resposta,
                limite máximo <strong>{{ status.effective.max_output_tokens_ceiling }}</strong>.
            </div>
        </div>

        <form class="space-y-4 rounded-lg border border-border p-4" @submit.prevent="save">
            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.ai_enabled" type="checkbox" class="size-4 rounded border-border" />
                <span class="font-medium">IA ativa</span>
            </label>
            <p class="-mt-2 text-xs text-muted-foreground">
                Desligar corta todos os pedidos imediatamente, sem apagar nada. A credencial mantém-se guardada.
            </p>

            <div class="grid gap-4 border-t border-border pt-4 sm:grid-cols-2">
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Fornecedor</span>
                    <select v-model="form.ai_provider" class="w-full rounded-md border border-border bg-background px-3 py-2">
                        <option value="">Não definido (usa o .env)</option>
                        <option v-for="provider in providers" :key="provider.value" :value="provider.value">{{ provider.label }}</option>
                    </select>
                    <span v-if="form.errors.ai_provider" class="mt-1 block text-xs text-red-600">{{ form.errors.ai_provider }}</span>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Modelo</span>
                    <input v-model="form.ai_model" type="text" placeholder="Ex.: gemini-3.6-flash" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                    <span v-if="form.errors.ai_model" class="mt-1 block text-xs text-red-600">{{ form.errors.ai_model }}</span>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Timeout (segundos)</span>
                    <input v-model.number="form.ai_timeout_seconds" type="number" min="1" max="120" placeholder="Herdado do .env" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                    <span v-if="form.errors.ai_timeout_seconds" class="mt-1 block text-xs text-red-600">{{ form.errors.ai_timeout_seconds }}</span>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Tokens de resposta por predefinição</span>
                    <input v-model.number="form.ai_max_output_tokens" type="number" min="64" max="32768" placeholder="Herdado do .env" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                    <!--
                     THIS FIELD WAS CALLED «Máximo» AND NÃO ERA. A funcionalidade
                     que declara precisar de mais — a síntese de acompanhamento
                     pede 3072 — recebe mais, e o operador não tinha como saber.
                     O teto real é o `max_output_tokens_ceiling` do .env, mostrado
                     acima em «Em vigor» e nunca ultrapassado por nada.
                    -->
                    <span class="mt-1 block text-xs text-muted-foreground">
                        O que uma resposta recebe por omissão. Uma funcionalidade que declare precisar de mais pode
                        subir acima deste valor — nunca acima do limite máximo
                        (<strong>{{ status.effective.max_output_tokens_ceiling }}</strong>), que se define no
                        <code>.env</code> em <code>LAPIS_AI_MAX_OUTPUT_TOKENS_CEILING</code>.
                    </span>
                    <span v-if="form.errors.ai_max_output_tokens" class="mt-1 block text-xs text-red-600">{{ form.errors.ai_max_output_tokens }}</span>
                </label>
            </div>

            <div class="grid gap-4 border-t border-border pt-4 sm:grid-cols-2">
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Pedidos por minuto (utilizador)</span>
                    <input v-model.number="form.ai_per_minute" type="number" min="1" max="600" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Pedidos por minuto (organização)</span>
                    <input v-model.number="form.ai_organization_per_minute" type="number" min="1" max="6000" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                </label>
            </div>

            <div class="border-t border-border pt-4">
                <h2 class="text-sm font-medium">Limites por funcionalidade</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Tetos técnicos de custo, não o que um plano inclui. Deixar em branco significa sem teto. Um plano
                    que defina o seu próprio limite tem prioridade sobre estes valores.
                </p>

                <div v-for="capability in meteredCapabilities" :key="capability.value" class="mt-4">
                    <div class="text-sm font-medium">{{ capability.label }}</div>
                    <!-- WHERE IT LIVES AND WHO HAS IT, on the same two lines as
                         the fields that cap it. An operator setting a ceiling
                         should not have to look up which screen they just
                         capped, or which customers it reaches. -->
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ capability.where }}</p>
                    <p
                        class="mt-0.5 text-xs"
                        :class="capability.plans.length === 0 ? 'text-amber-600' : 'text-muted-foreground'"
                    >
                        Planos: {{ planLabel(capability) }}
                    </p>
                    <div class="mt-1.5 grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">
                            <span class="mb-1 block text-xs text-muted-foreground">Por utilizador / dia</span>
                            <input v-model.number="form.ai_quotas[capability.value].user_daily" type="number" min="0" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block text-xs text-muted-foreground">Por organização / mês</span>
                            <input v-model.number="form.ai_quotas[capability.value].organization_monthly" type="number" min="0" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                        </label>
                    </div>
                </div>
            </div>

            <!-- THE POOL IS NOT A CAPABILITY AND SITS IN ITS OWN BLOCK. It is a
                 ceiling ACROSS every capability, it only applies to an
                 organization holding «Pool de IA da organização», and both its
                 fields are empty by default on purpose — a plafond is a
                 contract figure, and a default here would invent one for every
                 institutional customer at once. -->
            <div class="border-t border-border pt-4">
                <h2 class="text-sm font-medium">Plafond da organização (Institucional)</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Um teto mensal para o conjunto de todas as funcionalidades de IA, aplicado apenas a organizações
                    cujo plano inclui «Pool de IA da organização». Em branco significa sem plafond — os limites por
                    funcionalidade, acima, continuam a aplicar-se. Um contrato que defina o seu próprio plafond tem
                    prioridade sobre estes valores.
                </p>
                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">
                        <span class="mb-1 block text-xs text-muted-foreground">Pedidos da organização / mês</span>
                        <input v-model.number="form.ai_quotas[POOL_KEY].organization_monthly" type="number" min="0" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-xs text-muted-foreground">Teto individual dentro do plafond / mês</span>
                        <input v-model.number="form.ai_quotas[POOL_KEY].user_monthly" type="number" min="0" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                    </label>
                </div>
            </div>

            <div class="flex justify-end border-t border-border pt-4">
                <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50" :disabled="form.processing">
                    Guardar
                </button>
            </div>
        </form>

        <div class="space-y-3 rounded-lg border border-border p-4">
            <div>
                <h2 class="text-sm font-medium">Credencial do fornecedor</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Guardada cifrada no servidor. Nunca é devolvida a este ecrã, nem a nenhum outro — só pode ser substituída.
                </p>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-muted/40 px-3 py-2 text-sm">
                <span data-test="credential-state">{{ credentialLabel }}</span>
                <span v-if="credentialDate" class="text-xs text-muted-foreground">Desde {{ credentialDate }}</span>
            </div>

            <div v-if="!replacing" class="flex flex-wrap gap-2">
                <button type="button" class="rounded-md border border-border px-4 py-2 text-sm hover:bg-muted/40" @click="replacing = true">
                    Substituir credencial
                </button>
                <button type="button" class="rounded-md border border-border px-4 py-2 text-sm text-red-600 hover:bg-red-500/5" @click="removeCredential">
                    Remover credencial
                </button>
            </div>

            <form v-else class="space-y-2" @submit.prevent="saveCredential">
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Nova credencial</span>
                    <input
                        v-model="credentialForm.ai_credential"
                        type="password"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="Cole aqui a chave do fornecedor"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 font-mono"
                    />
                    <span v-if="credentialForm.errors.ai_credential" class="mt-1 block text-xs text-red-600">{{ credentialForm.errors.ai_credential }}</span>
                </label>
                <div class="flex gap-2">
                    <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50" :disabled="credentialForm.processing">
                        Guardar credencial
                    </button>
                    <button v-if="settings.credential_set" type="button" class="rounded-md border border-border px-4 py-2 text-sm hover:bg-muted/40" @click="replacing = false">
                        Cancelar
                    </button>
                </div>
            </form>

            <div class="border-t border-border pt-3">
                <button type="button" class="rounded-md border border-border px-4 py-2 text-sm hover:bg-muted/40" @click="testConnection">
                    Testar ligação
                </button>
                <p class="mt-1.5 text-xs text-muted-foreground">
                    Pede a palavra «OK» ao fornecedor com as definições em vigor. Confirma a credencial, o endereço e a rede —
                    e mais nada. Consome tokens e fica registado na auditoria.
                </p>
            </div>

            <!-- Two tests, and the distinction is deliberate. Uma ligação que
                 funciona não é um modelo que serve: o teste acima passa com uma
                 resposta de três tokens, e a aplicação pede seis secções sob uma
                 instrução de duas páginas. -->
            <div class="border-t border-border pt-3">
                <button type="button" class="rounded-md border border-border px-4 py-2 text-sm hover:bg-muted/40" @click="probeCapability">
                    Testar capacidade
                </button>
                <p class="mt-1.5 text-xs text-muted-foreground">
                    Pede ao modelo configurado uma síntese completa de seis secções, sobre um registo fictício escrito na
                    aplicação — nenhum dado de aluno ou de professor é enviado. Confirma que o modelo consegue produzir uma
                    resposta que a aplicação sabe ler, e não apenas que a ligação existe. Consome mais tokens do que o teste
                    de ligação e fica registado na auditoria.
                </p>
            </div>
        </div>

        <!-- ------------------------------------------- funcionalidades e planos -->
        <section aria-labelledby="funcionalidades-ia" class="rounded-lg border border-border p-4">
            <h2 id="funcionalidades-ia" class="text-sm font-medium">Funcionalidades de IA e planos</h2>
            <p class="mt-1 text-xs text-muted-foreground">
                O que cada funcionalidade é, onde vive na aplicação, e que planos a incluem. Lido da base de dados —
                alterar a composição comercial é uma alteração ao seeder de módulos, não a este ecrã.
            </p>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs text-muted-foreground">
                            <th scope="col" class="py-1.5 pr-3 font-medium">Funcionalidade</th>
                            <th scope="col" class="py-1.5 pr-3 font-medium">Onde</th>
                            <th scope="col" class="py-1.5 font-medium">Planos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="capability in capabilities" :key="capability.value" class="border-b border-border/50">
                            <th scope="row" class="py-1.5 pr-3 text-left font-normal">{{ capability.label }}</th>
                            <td class="py-1.5 pr-3 text-muted-foreground">{{ capability.where }}</td>
                            <td class="py-1.5" :class="capability.plans.length === 0 ? 'text-amber-600' : ''">
                                {{ planLabel(capability) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ------------------------------------------------------------ uso -->
        <section aria-labelledby="uso-ia" class="rounded-lg border border-border p-4">
            <h2 id="uso-ia" class="text-sm font-medium">Utilização em {{ monthLabel }}</h2>
            <p class="mt-1 text-xs text-muted-foreground">
                Todas as organizações desta instalação, mais os testes de ligação feitos daqui. Contagens e tokens —
                nunca perguntas, respostas, nomes ou conteúdo pedagógico: não existe coluna que os pudesse guardar.
            </p>

            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div class="rounded-lg bg-muted/40 p-3">
                    <dt class="text-xs text-muted-foreground">Pedidos</dt>
                    <dd class="text-lg font-semibold" data-test="usage-calls">{{ usage.totals.calls }}</dd>
                </div>
                <div class="rounded-lg bg-muted/40 p-3">
                    <dt class="text-xs text-muted-foreground">Concluídos</dt>
                    <dd class="text-lg font-semibold">{{ usage.totals.succeeded }}</dd>
                </div>
                <div class="rounded-lg bg-muted/40 p-3">
                    <dt class="text-xs text-muted-foreground">Com erro</dt>
                    <dd class="text-lg font-semibold">{{ usage.totals.failed }}</dd>
                </div>
                <div class="rounded-lg bg-muted/40 p-3">
                    <dt class="text-xs text-muted-foreground">Recusados</dt>
                    <dd class="text-lg font-semibold">{{ usage.totals.blocked }}</dd>
                </div>
                <div class="rounded-lg bg-muted/40 p-3">
                    <dt class="text-xs text-muted-foreground">Tokens</dt>
                    <dd class="text-lg font-semibold">{{ usage.totals.total_tokens }}</dd>
                </div>
            </dl>

            <div v-if="usage.by_use_case.length > 0" class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs text-muted-foreground">
                            <th scope="col" class="py-1.5 pr-3 font-medium">Tipo de pedido</th>
                            <th scope="col" class="py-1.5 pr-3 text-right font-medium">Pedidos</th>
                            <th scope="col" class="py-1.5 pr-3 text-right font-medium">Erros</th>
                            <th scope="col" class="py-1.5 pr-3 text-right font-medium">Recusados</th>
                            <th scope="col" class="py-1.5 text-right font-medium">Tokens</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in usage.by_use_case" :key="row.use_case" class="border-b border-border/50">
                            <th scope="row" class="py-1.5 pr-3 text-left font-normal">{{ row.label }}</th>
                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ row.calls }}</td>
                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ row.failed }}</td>
                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ row.blocked }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ row.total_tokens }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else class="mt-3 text-sm text-muted-foreground">Ainda não houve nenhum pedido de IA neste mês.</p>

            <!-- «Recusámos quatrocentos pedidos este mês» is the number that
                 says a ceiling is set wrong, and it is invisible unless the
                 refusals are written down and read back. -->
            <div v-if="usage.blocked_reasons.length > 0" class="mt-4 border-t border-border/60 pt-3">
                <h3 class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Motivos das recusas</h3>
                <ul class="mt-2 space-y-1">
                    <li v-for="row in usage.blocked_reasons" :key="row.reason" class="text-sm">
                        — {{ row.label }}: {{ row.count }}
                    </li>
                </ul>
            </div>
        </section>

        <p class="text-xs text-muted-foreground">
            Em produção, prefira um gestor de segredos externo com a chave injetada em <code>LAPIS_AI_KEY</code> e este campo vazio —
            uma coluna cifrada só é tão forte quanto a <code>APP_KEY</code> que a decifra. Ver <code>docs/ai-core-contract.md</code>.
        </p>
    </div>
</template>
