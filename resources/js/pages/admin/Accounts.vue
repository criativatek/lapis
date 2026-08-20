<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

type Row = {
    ulid: string;
    name: string;
    type: string;
    owner: string | null;
    owner_email: string | null;
    verified: boolean;
    active: boolean;
    plan: string | null;
    status: string | null;
    created_at: string | null;
};
type Paginator = {
    data: Row[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};

const props = defineProps<{ organizations: Paginator; search: string }>();

const term = ref(props.search);

function doSearch(): void {
    router.get('/admin', { search: term.value }, { preserveState: true, replace: true });
}

const statusClasses: Record<string, string> = {
    active: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    trial: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    suspended: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    expired: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <Head title="Contas — Backoffice" />

    <div class="space-y-4 p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Contas</h1>
                <p class="text-sm text-muted-foreground">{{ organizations.total }} organizações</p>
            </div>
            <div class="flex items-center gap-3">
                <form @submit.prevent="doSearch">
                    <input
                        v-model="term"
                        type="search"
                        placeholder="Procurar por nome ou email…"
                        class="w-64 rounded-md border border-border bg-background px-3 py-2 text-sm"
                    />
                </form>
                <Link
                    href="/admin/accounts/create"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90"
                >
                    Nova conta
                </Link>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Organização</th>
                        <th class="px-3 py-2 font-medium">Dono</th>
                        <th class="px-3 py-2 font-medium">Plano</th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2 font-medium">Acesso</th>
                        <th class="px-3 py-2 font-medium">Email</th>
                        <th class="px-3 py-2 font-medium">Criada</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="org in organizations.data" :key="org.ulid" class="hover:bg-muted/20">
                        <td class="px-3 py-2">
                            <Link :href="`/admin/accounts/${org.ulid}`" class="font-medium text-primary hover:underline">{{ org.name }}</Link>
                            <div class="text-xs text-muted-foreground">{{ org.type === 'institutional' ? 'Institucional' : 'Pessoal' }}</div>
                        </td>
                        <td class="px-3 py-2">
                            <div>{{ org.owner ?? '—' }}</div>
                            <div class="text-xs text-muted-foreground">{{ org.owner_email }}</div>
                        </td>
                        <td class="px-3 py-2">{{ org.plan ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <span v-if="org.status" class="rounded-full px-2 py-0.5 text-xs" :class="statusClasses[org.status] ?? 'bg-muted text-muted-foreground'">{{ org.status }}</span>
                            <span v-else class="text-xs text-muted-foreground">sem subscrição</span>
                        </td>
                        <td class="px-3 py-2">
                            <span :class="org.active ? 'text-emerald-600' : 'text-red-600'">{{ org.active ? 'ativa' : 'desativada' }}</span>
                        </td>
                        <td class="px-3 py-2">
                            <span :class="org.verified ? 'text-emerald-600' : 'text-amber-600'">{{ org.verified ? 'verificado' : 'por verificar' }}</span>
                        </td>
                        <td class="px-3 py-2 text-muted-foreground tabular-nums">{{ org.created_at }}</td>
                    </tr>
                    <tr v-if="organizations.data.length === 0">
                        <td colspan="7" class="px-3 py-10 text-center text-sm text-muted-foreground">Sem contas.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="organizations.links.length > 3" class="flex flex-wrap gap-1">
            <template v-for="(link, index) in organizations.links" :key="index">
                <!-- The label is the paginator's own («&laquo; Anterior», «1»),
                     so it carries entities and has to be rendered as HTML. On a
                     native element rather than on the component: v-html on a
                     component replaces whatever that component renders, which
                     for <Link> is the anchor itself. -->
                <Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded-md border border-border px-3 py-1.5 text-sm"
                    :class="link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted/40'"
                    preserve-scroll
                >
                    <span v-html="link.label" />
                </Link>
                <span v-else class="rounded-md border border-border px-3 py-1.5 text-sm text-muted-foreground opacity-50" v-html="link.label" />
            </template>
        </div>
    </div>
</template>
