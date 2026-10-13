<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Activity, ArrowLeft, Banknote, KeyRound, LifeBuoy, LogOut, Mail, ShieldCheck, Sparkles, UserPlus, Users } from '@lucide/vue';
import { computed } from 'vue';
import { Toaster } from '@/components/ui/sonner';
import { dashboard } from '@/routes';

const page = usePage();
const userName = computed(() => (page.props.auth as { user?: { name?: string } } | undefined)?.user?.name ?? '');

// Nav grows as backoffice slices land (accounts → create → comercial → SMTP → IA).
const baseNav = [
    { label: 'Contas', href: '/admin', icon: Users, active: (path: string) => path === '/admin' || (path.startsWith('/admin/accounts') && path !== '/admin/accounts/create') },
    { label: 'Nova conta', href: '/admin/accounts/create', icon: UserPlus, active: (path: string) => path === '/admin/accounts/create' },
    { label: 'Comercial', href: '/admin/commercial', icon: Banknote, active: (path: string) => path.startsWith('/admin/commercial') },
    { label: 'Capacidades', href: '/admin/capabilities', icon: KeyRound, active: (path: string) => path.startsWith('/admin/capabilities') },
    { label: 'Suporte', href: '/admin/support', icon: LifeBuoy, active: (path: string) => path.startsWith('/admin/support') },
    { label: 'Email (SMTP)', href: '/admin/settings', icon: Mail, active: (path: string) => path === '/admin/settings' },
    { label: 'Inteligência Artificial', href: '/admin/ai', icon: Sparkles, active: (path: string) => path.startsWith('/admin/ai') },
];

// «Utilização» só para quem tem a autorização própria. Esconder a entrada é
// apresentação: o servidor recusa as rotas a quem não a tem.
const usageNav = { label: 'Utilização', href: '/admin/usage', icon: Activity, active: (path: string) => path.startsWith('/admin/usage') };

const nav = computed(() => {
    const canViewUsage = (page.props.auth as { can_view_usage_insights?: boolean } | undefined)?.can_view_usage_insights === true;

    return canViewUsage ? [...baseNav, usageNav] : baseNav;
});

const currentPath = computed(() => page.url.split('?')[0]);

function logout(): void {
    router.post('/logout');
}
</script>

<template>
    <!-- Below `md` the sidebar becomes a bar on top with a sliding nav: a fixed
         240px column left a phone 135px for the page. From `md` up the layout
         is exactly what it was. -->
    <div class="flex min-h-screen flex-col bg-background text-foreground md:flex-row">
        <aside class="flex shrink-0 flex-col border-b border-border md:w-60 md:border-r md:border-b-0">
            <div class="flex items-center gap-2 border-b border-border px-4 py-4">
                <span class="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                    <ShieldCheck class="size-4" />
                </span>
                <div class="leading-tight">
                    <div class="text-sm font-semibold">Lapispro</div>
                    <div class="text-xs text-muted-foreground">Backoffice</div>
                </div>
            </div>

            <nav class="flex gap-1 overflow-x-auto p-2 md:block md:flex-1 md:space-y-1 md:overflow-visible md:p-3">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="flex min-h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-md px-3 py-2 text-sm md:min-h-0"
                    :class="item.active(currentPath) ? 'bg-accent text-accent-foreground font-medium' : 'text-muted-foreground hover:bg-muted/40'"
                >
                    <component :is="item.icon" class="size-4" /> {{ item.label }}
                </Link>
            </nav>

            <div class="flex gap-1 overflow-x-auto border-t border-border p-2 text-sm md:block md:p-3">
                <!-- The way out of operator mode and back into the teacher-facing
                     application. Named for the product, not for «a app», so it is
                     unambiguous which of the two areas it lands in. -->
                <Link
                    :href="dashboard()"
                    class="flex min-h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-md px-3 py-2 text-muted-foreground hover:bg-muted/40 md:min-h-0"
                    data-test="back-to-app"
                >
                    <ArrowLeft class="size-4" /> Voltar ao Lapispro
                </Link>
                <button type="button" class="flex min-h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-md px-3 py-2 text-left text-muted-foreground hover:bg-muted/40 md:mt-1 md:min-h-0 md:w-full" @click="logout">
                    <LogOut class="size-4" /> Terminar sessão
                </button>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex items-center justify-between gap-3 border-b border-border px-4 py-3 md:px-6">
                <span class="truncate text-sm text-muted-foreground">Administração da plataforma</span>
                <span class="truncate text-sm font-medium">{{ userName }}</span>
            </header>
            <main class="min-w-0 flex-1">
                <slot />
            </main>
        </div>
        <Toaster />
    </div>
</template>
