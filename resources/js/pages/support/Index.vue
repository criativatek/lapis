<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

/**
 * Os pedidos DESTA pessoa — e de mais ninguém.
 *
 * O servidor filtra por `user_id`; um colega da mesma organização não aparece
 * aqui nem que partilhe tudo o resto (ADR-0011 §3). Não há campo de pesquisa
 * por referência, de propósito: `SUP-XXXXXX` é um número de protocolo e não uma
 * forma de chegar a um pedido.
 */

type SupportRow = {
    ulid: string;
    reference: string;
    subject: string | null;
    categoryLabel: string;
    status: string;
    statusLabel: string;
    createdAt: string | null;
    resolvedAt: string | null;
    autoResolved: boolean;
};

defineProps<{ requests: SupportRow[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Suporte', href: '/support' }] },
});

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('pt-PT') : '—';
}

const statusClasses: Record<string, string> = {
    open: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    in_progress:
        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    waiting_for_user:
        'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300',
    resolved: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <Head title="Suporte" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <Heading
                variant="small"
                title="Suporte"
                description="Os seus pedidos e as respostas da equipa."
            />
            <Button as-child size="sm">
                <Link href="/support/novo">Novo pedido</Link>
            </Button>
        </div>

        <p v-if="requests.length === 0" class="text-sm text-muted-foreground">
            Ainda não abriu nenhum pedido. Se precisar de ajuda, comece por
            procurar no
            <Link href="/help" class="underline">Centro de Ajuda</Link> — e se
            não encontrar resposta, fale connosco.
        </p>

        <ul v-else class="space-y-2">
            <li
                v-for="pedido in requests"
                :key="pedido.ulid"
                class="rounded-lg border border-border"
            >
                <Link
                    :href="`/support/${pedido.ulid}`"
                    class="flex flex-wrap items-center gap-x-4 gap-y-2 p-4 hover:bg-muted/50"
                >
                    <span
                        class="font-mono text-xs tracking-wide text-muted-foreground"
                        >{{ pedido.reference }}</span
                    >
                    <span class="min-w-0 flex-1 text-sm font-medium">{{
                        pedido.subject ?? '—'
                    }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        pedido.categoryLabel
                    }}</span>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs"
                        :class="statusClasses[pedido.status]"
                        >{{ pedido.statusLabel }}</span
                    >
                    <span class="text-xs text-muted-foreground">{{
                        formatDate(pedido.createdAt)
                    }}</span>
                </Link>
            </li>
        </ul>
    </div>
</template>
