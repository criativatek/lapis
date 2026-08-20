<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type Account = {
    ulid: string;
    name: string;
    type: string;
    created_at: string | null;
    owner: {
        name: string | null;
        email: string | null;
        verified: boolean;
        is_platform_admin: boolean;
        active: boolean;
        deactivated_at: string | null;
    };
    members_count: number;
    plan: string | null;
    plan_key: string | null;
    status: string | null;
    modules: string[];
    deactivation_refusal: string | null;
    blocking: Record<string, number>;
};

const props = defineProps<{ account: Account; plans: { key: string; name: string }[] }>();

const base = computed(() => `/admin/accounts/${props.account.ulid}`);

// Every guard in the controller writes its refusal to the same `account` key,
// so there is one place to render them all.
const refusal = computed(() => (usePage().props.errors as Record<string, string>)?.account ?? null);

const blockingEntries = computed(() => Object.entries(props.account.blocking));
const canDelete = computed(
    () => blockingEntries.value.length === 0 && !props.account.owner.is_platform_admin && props.account.type === 'personal',
);

const userForm = useForm({
    name: props.account.owner.name ?? '',
    email: props.account.owner.email ?? '',
});

function post(path: string, data: Record<string, string> = {}): void {
    router.post(`${base.value}${path}`, data, { preserveScroll: true });
}

function saveUser(): void {
    userForm.put(`${base.value}/user`, { preserveScroll: true });
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
        </div>

        <p
            v-if="refusal"
            class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
        >
            {{ refusal }}
        </p>

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

        <!-- Remoção definitiva -->
        <section class="space-y-3 rounded-lg border border-red-200 p-4 dark:border-red-900">
            <h2 class="text-sm font-medium text-red-700 dark:text-red-400">Apagar definitivamente</h2>
            <p class="text-sm text-muted-foreground">
                A forma normal de remover uma conta é <strong>desativá-la</strong>: o acesso acaba e os dados ficam. Apagar é
                excecional e só é possível numa conta pessoal que nunca chegou a ter nada.
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
