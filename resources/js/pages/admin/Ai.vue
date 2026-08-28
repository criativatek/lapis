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

type Quotas = Record<string, { user_daily: number | null; organization_monthly: number | null }>;

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
        per_minute: number;
        organization_per_minute: number;
    };
};

const props = defineProps<{
    settings: Settings;
    status: Status;
    providers: { value: string; label: string }[];
    capabilities: { value: string; label: string; granted_by_no_plan: boolean }[];
}>();

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
</script>

<template>
    <Head title="Inteligência Artificial — Backoffice" />

    <div class="mx-auto w-full max-w-2xl space-y-5 p-6">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Inteligência Artificial</h1>
            <p class="text-sm text-muted-foreground">
                O motor que atende as funcionalidades assistidas por IA. Estas definições sobrepõem-se ao <code>.env</code>.
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
                máximo de <strong>{{ status.effective.max_output_tokens }}</strong> tokens de resposta.
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
                    <input v-model="form.ai_model" type="text" placeholder="Ex.: gemini-2.5-flash" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                    <span v-if="form.errors.ai_model" class="mt-1 block text-xs text-red-600">{{ form.errors.ai_model }}</span>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Timeout (segundos)</span>
                    <input v-model.number="form.ai_timeout_seconds" type="number" min="1" max="120" placeholder="Herdado do .env" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                    <span v-if="form.errors.ai_timeout_seconds" class="mt-1 block text-xs text-red-600">{{ form.errors.ai_timeout_seconds }}</span>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Máximo de tokens de resposta</span>
                    <input v-model.number="form.ai_max_output_tokens" type="number" min="64" max="32768" placeholder="Herdado do .env" class="w-full rounded-md border border-border bg-background px-3 py-2" />
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
                    Tetos técnicos de custo, não o que um plano inclui. Deixar em branco significa sem teto.
                </p>

                <div v-for="capability in capabilities" :key="capability.value" class="mt-3">
                    <div class="text-sm font-medium">{{ capability.label }}</div>
                    <p v-if="capability.granted_by_no_plan" class="mt-0.5 text-xs text-amber-600">
                        Nenhum plano inclui esta funcionalidade — a composição comercial ainda não foi decidida, por isso nenhum pedido chega ao motor.
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
                    Faz um pedido real ao fornecedor com as definições em vigor. Consome tokens e fica registado na auditoria.
                </p>
            </div>
        </div>

        <p class="text-xs text-muted-foreground">
            Em produção, prefira um gestor de segredos externo com a chave injetada em <code>LAPIS_AI_KEY</code> e este campo vazio —
            uma coluna cifrada só é tão forte quanto a <code>APP_KEY</code> que a decifra. Ver <code>docs/ai-core-contract.md</code>.
        </p>
    </div>
</template>
