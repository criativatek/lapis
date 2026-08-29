<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

type Member = {
    name: string;
    email: string;
    is_owner: boolean;
    active: boolean;
};

// Read-only (§19 of the lifecycle brief) — this backoffice has no button
// that acts on a closure request. The person recovers or exports it
// themselves; an operator only needs to see it.
type ClosureStatus = {
    requested_at: string;
    scheduled_deletion_at: string;
    days_remaining: number;
    recoverable: boolean;
    eligible_for_deletion: boolean;
};

type Account = {
    ulid: string;
    name: string;
    type: string;
    created_at: string | null;
    /** Exists to try the product out — nothing was ever promised to it. Grants nothing. */
    is_test_account: boolean;
    owner: {
        name: string | null;
        email: string | null;
        verified: boolean;
        is_platform_admin: boolean;
        active: boolean;
        deactivated_at: string | null;
        closure: ClosureStatus | null;
    };
    members_count: number;
    members: Member[];
    plan: string | null;
    plan_key: string | null;
    /** Which version of that plan was contracted (ADR-0008). Null when there is no subscription. */
    plan_version: number | null;
    status: string | null;
    modules: string[];
    deactivation_refusal: string | null;
    blocking: Record<string, number>;
    closure: ClosureStatus | null;
};

const props = defineProps<{ account: Account; plans: { key: string; name: string }[] }>();

const base = computed(() => `/admin/accounts/${props.account.ulid}`);

// Every guard in the controller writes its refusal to the same `account` key,
// so there is one place to render them all — except adding a member, which
// writes to `member` so its error does not clobber this one mid-form.
const refusal = computed(() => (usePage().props.errors as Record<string, string>)?.account ?? null);

const blockingEntries = computed(() => Object.entries(props.account.blocking));
const canDelete = computed(
    () => blockingEntries.value.length === 0 && !props.account.owner.is_platform_admin && props.account.type === 'personal',
);

const userForm = useForm({
    name: props.account.owner.name ?? '',
    email: props.account.owner.email ?? '',
});

const memberForm = useForm({ email: '' });
const resetPasswordForm = useForm({});
const temporaryPasswordForm = useForm({});
const resetPasswordDialogOpen = ref(false);
const temporaryPasswordDialogOpen = ref(false);
const temporaryPassword = ref<{ value: string; email: string } | null>(null);
const page = usePage();

watch(
    () => page.flash?.temporary_password as { value?: string; email?: string } | undefined,
    (generated) => {
        if (generated?.value && generated.email) {
            temporaryPassword.value = { value: generated.value, email: generated.email };
        }
    },
    { immediate: true },
);

function post(path: string, data: Record<string, string> = {}): void {
    router.post(`${base.value}${path}`, data, { preserveScroll: true });
}

function copyTemporaryPassword(): void {
    if (temporaryPassword.value !== null) {
        void navigator.clipboard.writeText(temporaryPassword.value.value);
    }
}

function requestPasswordReset(): void {
    resetPasswordForm.post(`${base.value}/reset-password`, {
        preserveScroll: true,
        onSuccess: () => (resetPasswordDialogOpen.value = false),
    });
}

function generateTemporaryPassword(): void {
    temporaryPasswordForm.post(`${base.value}/temporary-password`, {
        preserveScroll: true,
        onSuccess: () => (temporaryPasswordDialogOpen.value = false),
    });
}

function saveUser(): void {
    userForm.put(`${base.value}/user`, { preserveScroll: true });
}

function addMember(): void {
    memberForm.post(`${base.value}/members`, {
        preserveScroll: true,
        onSuccess: () => memberForm.reset(),
    });
}

const memberRefusal = computed(() => (usePage().props.errors as Record<string, string>)?.member ?? null);

/**
 * «Conta de teste», marcada ou desmarcada — e sempre com o estado que se quer,
 * nunca «inverte o que lá estiver». O pedido leva `is_test_account` explícito,
 * de modo que dois separadores abertos na mesma conta não deixam a marca onde
 * calhar. Confirmar é obrigatório: isto é o que o pré-voo comercial lê para
 * decidir de quem não tem de perguntar.
 */
function setTestAccount(next: boolean): void {
    const question = next
        ? `Marcar «${props.account.name}» como conta de teste? Fica excluída do pré-voo comercial — não muda plano, módulos nem acesso.`
        : `Deixar de tratar «${props.account.name}» como conta de teste? Passa a ser exigida condição comercial no pré-voo.`;

    if (!confirm(question)) {
        return;
    }

    router.post(`${base.value}/test-account`, { is_test_account: next }, { preserveScroll: true });
}

function destroy(): void {
    if (!confirm(`Apagar definitivamente a conta de ${props.account.owner.email}? Esta ação não pode ser anulada.`)) {
        return;
    }

    router.delete(base.value);
}
</script>

<template>
    <Head :title="`${account.name} — Backoffice`" />

    <div class="mx-auto w-full max-w-3xl space-y-5 p-6">
        <div>
            <Link href="/admin" class="text-sm text-muted-foreground hover:underline">← Contas</Link>
            <h1 class="mt-1 text-xl font-semibold tracking-tight">{{ account.name }}</h1>
            <p class="text-sm text-muted-foreground">
                {{ account.type === 'institutional' ? 'Institucional' : 'Pessoal' }} · criada {{ account.created_at }} ·
                {{ account.members_count }} {{ account.members_count === 1 ? 'membro' : 'membros' }}
            </p>
            <p v-if="account.closure" class="mt-1 text-sm">
                <span
                    class="rounded-full px-2 py-0.5 text-xs"
                    :class="
                        account.closure.eligible_for_deletion
                            ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300'
                            : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                    "
                >
                    organização em encerramento —
                    {{ account.closure.eligible_for_deletion ? 'elegível para eliminação' : `${account.closure.days_remaining} dia(s) restantes` }}
                </span>
            </p>
            <p v-if="account.is_test_account" class="mt-1 text-sm">
                <span class="rounded-full bg-sky-100 px-2 py-0.5 text-xs text-sky-800 dark:bg-sky-950 dark:text-sky-300"> conta de teste </span>
            </p>
        </div>

        <p
            v-if="refusal"
            class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
        >
            {{ refusal }}
        </p>

        <div
            v-if="temporaryPassword"
            class="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
        >
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-sm font-medium">Palavra-passe temporária</h2>
                    <p class="text-sm">Guarde-a agora. Esta palavra-passe só é mostrada uma vez para {{ temporaryPassword.email }}.</p>
                </div>
                <button type="button" class="text-sm underline" @click="temporaryPassword = null">Fechar</button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <code class="rounded-md bg-background px-3 py-2 font-mono text-sm">{{ temporaryPassword.value }}</code>
                <Button type="button" variant="outline" size="sm" @click="copyTemporaryPassword">Copiar</Button>
            </div>
        </div>

        <!-- Dono -->
        <section class="space-y-3 rounded-lg border border-border p-4">
            <h2 class="text-sm font-medium">Dono</h2>

            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="rounded-full px-2 py-0.5 text-xs"
                    :class="
                        account.owner.verified
                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                            : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                    "
                >
                    {{ account.owner.verified ? 'email verificado' : 'email por verificar' }}
                </span>
                <span v-if="account.owner.is_platform_admin" class="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">
                    admin da plataforma
                </span>
                <span
                    v-if="!account.owner.active"
                    class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800 dark:bg-red-950 dark:text-red-300"
                >
                    desativada em {{ account.owner.deactivated_at }}
                </span>
                <span
                    v-if="account.owner.closure"
                    class="rounded-full px-2 py-0.5 text-xs"
                    :class="
                        account.owner.closure.eligible_for_deletion
                            ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300'
                            : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                    "
                >
                    conta em encerramento —
                    {{ account.owner.closure.eligible_for_deletion ? 'elegível para eliminação' : `${account.owner.closure.days_remaining} dia(s) restantes` }}
                </span>
            </div>

            <div class="space-y-3">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Dados do utilizador</h3>
                <form class="space-y-3" @submit.prevent="saveUser">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block text-sm">
                            <span class="mb-1 block font-medium">Nome</span>
                            <input v-model="userForm.name" type="text" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                            <span v-if="userForm.errors.name" class="mt-1 block text-xs text-red-600">{{ userForm.errors.name }}</span>
                        </label>
                        <label class="block text-sm">
                            <span class="mb-1 block font-medium">Email</span>
                            <input v-model="userForm.email" type="email" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                            <span v-if="userForm.errors.email" class="mt-1 block text-xs text-red-600">{{ userForm.errors.email }}</span>
                            <span class="mt-1 block text-xs text-muted-foreground">Mudar o email obriga a nova verificação.</span>
                        </label>
                    </div>
                    <button
                        type="submit"
                        class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                        :disabled="userForm.processing || !userForm.isDirty"
                    >
                        Guardar alterações
                    </button>
                </form>
            </div>

            <div class="space-y-3 border-t border-border pt-3">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Ações administrativas</h3>

                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="!account.owner.verified"
                        type="button"
                        class="rounded-md border border-emerald-600 px-3 py-1.5 text-sm text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950"
                        @click="post('/verify-email')"
                    >
                        Verificar email
                    </button>
                    <Dialog v-model:open="resetPasswordDialogOpen">
                        <DialogTrigger as-child>
                            <button type="button" class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40">
                                Repor palavra-passe
                            </button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader class="space-y-3">
                                <DialogTitle>Repor palavra-passe</DialogTitle>
                                <DialogDescription>
                                    Será enviado um link de redefinição de palavra-passe para {{ account.owner.email }}.
                                </DialogDescription>
                                <DialogDescription v-if="!account.owner.active">
                                    Este utilizador está desativado — repor a palavra-passe não reativa o acesso.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter class="gap-2">
                                <DialogClose as-child>
                                    <Button variant="secondary">Cancelar</Button>
                                </DialogClose>
                                <Button :disabled="resetPasswordForm.processing" @click="requestPasswordReset">Enviar link</Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                    <Dialog v-model:open="temporaryPasswordDialogOpen">
                        <DialogTrigger as-child>
                            <button type="button" class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40">
                                Gerar palavra-passe temporária
                            </button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader class="space-y-3">
                                <DialogTitle>Gerar palavra-passe temporária</DialogTitle>
                                <DialogDescription>
                                    Será gerada uma nova palavra-passe para {{ account.owner.email }} e mostrada uma única vez. A palavra-passe atual deixará de funcionar.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter class="gap-2">
                                <DialogClose as-child>
                                    <Button variant="secondary">Cancelar</Button>
                                </DialogClose>
                                <Button :disabled="temporaryPasswordForm.processing" @click="generateTemporaryPassword">Gerar</Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                    <button type="button" class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40" @click="post('/toggle-admin')">
                        {{ account.owner.is_platform_admin ? 'Revogar admin da plataforma' : 'Tornar admin da plataforma' }}
                    </button>
                    <button
                        v-if="!account.owner.is_platform_admin && account.owner.active"
                        type="button"
                        class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                        title="Apenas para suporte. Permite visualizar a aplicação com o contexto deste utilizador."
                        @click="post('/impersonate')"
                    >
                        Aceder como utilizador
                    </button>

                    <button
                        v-if="account.owner.active"
                        type="button"
                        class="rounded-md border border-red-600 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50 dark:text-red-400 dark:hover:bg-red-950"
                        :disabled="account.deactivation_refusal !== null"
                        :title="account.deactivation_refusal ?? 'Impede o acesso desta pessoa, sem tocar na subscrição da organização.'"
                        @click="post('/deactivate')"
                    >
                        Desativar utilizador
                    </button>
                    <button
                        v-else
                        type="button"
                        class="rounded-md border border-emerald-600 px-3 py-1.5 text-sm text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950"
                        @click="post('/activate')"
                    >
                        Reativar utilizador
                    </button>
                </div>

                <p v-if="account.deactivation_refusal" class="text-xs text-muted-foreground">{{ account.deactivation_refusal }}</p>
            </div>
        </section>

        <!-- Membros (só institucional — uma organização pessoal tem sempre um único membro) -->
        <section v-if="account.type === 'institutional'" class="space-y-3 rounded-lg border border-border p-4">
            <h2 class="text-sm font-medium">Membros ({{ account.members.length }})</h2>

            <ul class="divide-y divide-border overflow-hidden rounded-lg border border-border">
                <li v-for="member in account.members" :key="member.email" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                    <div class="min-w-0">
                        <div class="truncate font-medium">{{ member.name }}</div>
                        <div class="truncate text-xs text-muted-foreground">{{ member.email }}</div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5">
                        <span
                            v-if="!member.active"
                            class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800 dark:bg-red-950 dark:text-red-300"
                        >
                            desativado
                        </span>
                        <span
                            class="rounded-full px-2 py-0.5 text-xs"
                            :class="member.is_owner ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'"
                        >
                            {{ member.is_owner ? 'Responsável' : 'Membro' }}
                        </span>
                    </div>
                </li>
            </ul>

            <p v-if="memberRefusal" class="text-xs text-red-600">{{ memberRefusal }}</p>

            <form class="flex items-end gap-2" @submit.prevent="addMember">
                <label class="block flex-1 text-sm">
                    <span class="mb-1 block font-medium">Adicionar membro existente</span>
                    <input
                        v-model="memberForm.email"
                        type="email"
                        placeholder="Ex.: email@escola.pt"
                        class="w-full rounded-md border border-border bg-background px-3 py-2"
                    />
                    <span v-if="memberForm.errors.email" class="mt-1 block text-xs text-red-600">{{ memberForm.errors.email }}</span>
                </label>
                <button
                    type="submit"
                    class="rounded-md border border-border px-3 py-2 text-sm hover:bg-muted/40 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="memberForm.processing || memberForm.email === ''"
                >
                    Adicionar
                </button>
            </form>
            <p class="text-xs text-muted-foreground">
                Tem de já ter conta no Lapispro. Continua também na sua própria organização pessoal, se tiver uma.
            </p>
        </section>

        <!-- Subscrição -->
        <section class="space-y-3 rounded-lg border border-border p-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium">Subscrição</h2>
                <span
                    v-if="account.status"
                    class="rounded-full px-2 py-0.5 text-xs"
                    :class="
                        account.status === 'active'
                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                            : account.status === 'suspended'
                              ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300'
                              : 'bg-muted text-muted-foreground'
                    "
                >
                    {{ account.status }}
                </span>
            </div>
            <div class="text-sm">
                Plano atual: <span class="font-medium">{{ account.plan ?? 'sem subscrição' }}</span>
                <!--
                    A versão contratada, quando existe. Não é decoração: desde
                    a ADR-0008 duas contas no «Pro» podem estar em ofertas
                    diferentes, e é isto que permite responder «v1 ou v2?» sem
                    abrir a base de dados. Leitura apenas — mudar de versão é um
                    ato deliberado e não se faz a partir de uma etiqueta.
                -->
                <span v-if="account.plan_version !== null" class="ml-1 rounded bg-muted px-1.5 py-0.5 font-mono text-xs text-muted-foreground">
                    v{{ account.plan_version }}
                </span>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-muted-foreground">Mudar plano:</span>
                <button
                    v-for="plan in plans"
                    :key="plan.key"
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm"
                    :class="plan.key === account.plan_key ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted/40'"
                    @click="post('/plan', { plan_key: plan.key })"
                >
                    {{ plan.name }}
                </button>
            </div>
            <div class="flex gap-2">
                <button
                    v-if="account.status !== 'suspended'"
                    type="button"
                    class="rounded-md border border-red-600 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950"
                    @click="post('/suspend')"
                >
                    Suspender subscrição
                </button>
                <button
                    v-else
                    type="button"
                    class="rounded-md border border-emerald-600 px-3 py-1.5 text-sm text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950"
                    @click="post('/reactivate')"
                >
                    Reativar subscrição
                </button>
            </div>
            <p class="text-xs text-muted-foreground">
                Desativar o utilizador (acima) retira o acesso apenas a esta pessoa. Suspender a subscrição retira o acesso ao produto a toda a
                organização.
            </p>
        </section>

        <!-- Módulos -->
        <section class="space-y-2 rounded-lg border border-border p-4">
            <h2 class="text-sm font-medium">Módulos ativos ({{ account.modules.length }})</h2>
            <div class="flex flex-wrap gap-1.5">
                <span v-for="mod in account.modules" :key="mod" class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ mod }}</span>
                <span v-if="account.modules.length === 0" class="text-xs text-muted-foreground">Sem módulos.</span>
            </div>
        </section>

        <!-- Conta de teste -->
        <section class="space-y-3 rounded-lg border border-border p-4">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-sm font-medium">Natureza da conta</h2>
                <span
                    class="rounded-full px-2 py-0.5 text-xs"
                    :class="
                        account.is_test_account
                            ? 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300'
                            : 'bg-muted text-muted-foreground'
                    "
                >
                    {{ account.is_test_account ? 'conta de teste' : 'conta real' }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground">
                Uma conta de teste existe para experimentar o produto e não teve nada prometido. A marca <strong>não</strong> altera plano,
                módulos, limites, versão do plano, acesso, retenção nem IA, e <strong>não</strong> é uma condição comercial — serve apenas
                para o pré-voo comercial saber de quem não tem de perguntar.
            </p>
            <Button type="button" variant="outline" size="sm" @click="setTestAccount(!account.is_test_account)">
                {{ account.is_test_account ? 'Deixar de ser conta de teste' : 'Marcar como conta de teste' }}
            </Button>
        </section>

        <!-- Remoção definitiva -->
        <section class="space-y-3 rounded-lg border border-red-200 p-4 dark:border-red-900">
            <h2 class="text-sm font-medium text-red-700 dark:text-red-400">Apagar definitivamente</h2>
            <p class="text-sm text-muted-foreground">
                A forma normal de impedir o acesso de um utilizador é <strong>desativá-lo</strong>: o acesso termina, mas os dados
                são preservados. A eliminação definitiva é excecional e só é possível quando não existem dados associados.
            </p>

            <div v-if="blockingEntries.length > 0" class="space-y-1">
                <p class="text-sm">Esta conta tem dados associados:</p>
                <ul class="list-inside list-disc text-sm text-muted-foreground">
                    <li v-for="[label, total] in blockingEntries" :key="label">{{ label }}: {{ total }}</li>
                </ul>
            </div>

            <button
                type="button"
                class="rounded-md border border-red-600 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50 dark:text-red-400 dark:hover:bg-red-950"
                :disabled="!canDelete"
                @click="destroy"
            >
                Apagar conta
            </button>
        </section>
    </div>
</template>
