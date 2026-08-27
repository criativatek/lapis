<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, LogOut, Mail, ShieldCheck, UserPlus, Users } from '@lucide/vue';
import { computed } from 'vue';
import { Toaster } from '@/components/ui/sonner';
import { dashboard } from '@/routes';

const page = usePage();
const userName = computed(() => (page.props.auth as { user?: { name?: string } } | undefined)?.user?.name ?? '');

// Nav grows as backoffice slices land (accounts → create → SMTP).
const nav = [
    { label: 'Contas', href: '/admin', icon: Users, active: (path: string) => path === '/admin' || (path.startsWith('/admin/accounts') && path !== '/admin/accounts/create') },
    { label: 'Nova conta', href: '/admin/accounts/create', icon: UserPlus, active: (path: string) => path === '/admin/accounts/create' },
    { label: 'Email (SMTP)', href: '/admin/settings', icon: Mail, active: (path: string) => path === '/admin/settings' },
];

const currentPath = computed(() => page.url.split('?')[0]);

function logout(): void {
    router.post('/logout');
}
</script>

<template>
    <div class="flex min-h-screen bg-background text-foreground">
        <aside class="flex w-60 shrink-0 flex-col border-r border-border">
            <div class="flex items-center gap-2 border-b border-border px-4 py-4">
                <span class="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                    <ShieldCheck class="size-4" />
                </span>
                <div class="leading-tight">
                    <div class="text-sm font-semibold">Lapispro</div>
                    <div class="text-xs text-muted-foreground">Backoffice</div>
                </div>
            </div>

            <nav class="flex-1 space-y-1 p-3">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-2 rounded-md px-3 py-2 text-sm"
                    :class="item.active(currentPath) ? 'bg-accent text-accent-foreground font-medium' : 'text-muted-foreground hover:bg-muted/40'"
                >
                    <component :is="item.icon" class="size-4" /> {{ item.label }}
                </Link>
            </nav>

            <div class="border-t border-border p-3 text-sm">
                <!-- The way out of operator mode and back into the teacher-facing
                     application. Named for the product, not for «a app», so it is
                     unambiguous which of the two areas it lands in. -->
                <Link
                    :href="dashboard()"
                    class="flex items-center gap-2 rounded-md px-3 py-2 text-muted-foreground hover:bg-muted/40"
                    data-test="back-to-app"
                >
                    <ArrowLeft class="size-4" /> Voltar ao Lapispro
                </Link>
                <button type="button" class="mt-1 flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-muted-foreground hover:bg-muted/40" @click="logout">
                    <LogOut class="size-4" /> Terminar sessão
                </button>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex items-center justify-between border-b border-border px-6 py-3">
                <span class="text-sm text-muted-foreground">Administração da plataforma</span>
                <span class="text-sm font-medium">{{ userName }}</span>
            </header>
            <main class="min-w-0 flex-1">
                <slot />
            </main>
        </div>
        <Toaster />
    </div>
</template>
