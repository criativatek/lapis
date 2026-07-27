<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

type Account = {
    ulid: string;
    name: string;
    type: string;
    created_at: string | null;
    owner: { name: string | null; email: string | null; verified: boolean; is_platform_admin: boolean };
    members_count: number;
    plan: string | null;
    plan_key: string | null;
    status: string | null;
    modules: string[];
};

const props = defineProps<{ account: Account; plans: { key: string; name: string }[] }>();

const base = computed(() => `/admin/accounts/${props.account.ulid}`);

function post(path: string, data: Record<string, string> = {}): void {
    router.post(`${base.value}${path}`, data, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`${account.name} — Backoffice`" />

    <div class="mx-auto w-full max-w-3xl space-y-5 p-6">
        <div>
            <Link href="/admin" class="text-sm text-muted-foreground hover:underline">← Contas</Link>
            <h1 class="mt-1 text-xl font-semibold tracking-tight">{{ account.name }}</h1>
            <p class="text-sm text-muted-foreground">{{ account.type === 'institutional' ? 'Institucional' : 'Pessoal' }} · criada {{ account.created_at }}</p>
        </div>

        <!-- Dono -->
        <section class="space-y-3 rounded-lg border border-border p-4">
            <h2 class="text-sm font-medium">Dono</h2>
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <div>
                    <div class="font-medium">{{ account.owner.name }}</div>
                    <div class="text-muted-foreground">{{ account.owner.email }}</div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="rounded-full px-2 py-0.5 text-xs" :class="account.owner.verified ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'">
                        {{ account.owner.verified ? 'verificado' : 'por verificar' }}
                    </span>
                    <span v-if="account.owner.is_platform_admin" class="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">admin</span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button v-if="!account.owner.verified" type="button" class="rounded-md border border-emerald-600 px-3 py-1.5 text-sm text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950" @click="post('/verify-email')">
                    Verificar email
                </button>
                <button type="button" class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40" @click="post('/toggle-admin')">
                    {{ account.owner.is_platform_admin ? 'Revogar admin da plataforma' : 'Tornar admin da plataforma' }}
                </button>
                <button v-if="!account.owner.is_platform_admin" type="button" class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40" @click="post('/impersonate')">
                    Impersonar (suporte)
                </button>
            </div>
        </section>

        <!-- Subscrição -->
        <section class="space-y-3 rounded-lg border border-border p-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium">Subscrição</h2>
                <span v-if="account.status" class="rounded-full px-2 py-0.5 text-xs" :class="account.status === 'active' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : account.status === 'suspended' ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' : 'bg-muted text-muted-foreground'">
                    {{ account.status }}
                </span>
            </div>
            <div class="text-sm">Plano atual: <span class="font-medium">{{ account.plan ?? 'sem subscrição' }}</span></div>

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
                <button v-if="account.status !== 'suspended'" type="button" class="rounded-md border border-red-600 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950" @click="post('/suspend')">
                    Suspender
                </button>
                <button v-else type="button" class="rounded-md border border-emerald-600 px-3 py-1.5 text-sm text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950" @click="post('/reactivate')">
                    Reativar
                </button>
            </div>
        </section>

        <!-- Módulos -->
        <section class="space-y-2 rounded-lg border border-border p-4">
            <h2 class="text-sm font-medium">Módulos ativos ({{ account.modules.length }})</h2>
            <div class="flex flex-wrap gap-1.5">
                <span v-for="mod in account.modules" :key="mod" class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ mod }}</span>
                <span v-if="account.modules.length === 0" class="text-xs text-muted-foreground">Sem módulos.</span>
            </div>
        </section>
    </div>
</template>
