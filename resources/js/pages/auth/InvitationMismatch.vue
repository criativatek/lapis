<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { UserX } from '@lucide/vue';
import { logout } from '@/routes';

defineProps<{
    organizationName: string;
    invitedEmail: string;
    currentEmail: string;
}>();

defineOptions({
    layout: {
        title: 'Convite para outra conta',
        description: '',
    },
});

function handleLogout(): void {
    router.flushAll();
}
</script>

<template>
    <Head title="Convite para outra conta" />

    <div class="flex flex-col items-center gap-4 text-center">
        <UserX class="size-8 text-muted-foreground" />
        <p class="text-sm text-muted-foreground">
            O convite para <strong>{{ organizationName }}</strong> foi enviado para <strong>{{ invitedEmail }}</strong
            >, mas está autenticado como <strong>{{ currentEmail }}</strong
            >. Termine a sessão atual e abra o link do convite outra vez para o aceitar.
        </p>
        <Link :href="logout()" as="button" class="text-sm underline underline-offset-4" @click="handleLogout">
            Terminar sessão
        </Link>
    </div>
</template>
