<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ClosureStatus, OrganizationClosureStatus } from '@/types/closure';

const page = usePage();

const account = computed(() => page.props.accountClosure as ClosureStatus | null);
const organization = computed(() => page.props.organizationClosure as OrganizationClosureStatus | null);

function reactivateAccount(): void {
    router.delete('/settings/account-closure', { preserveScroll: true });
}

function reactivateOrganization(): void {
    router.delete('/team/closure', { preserveScroll: true });
}
</script>

<template>
    <div
        v-if="account"
        class="flex flex-wrap items-center justify-center gap-3 bg-red-600 px-4 py-1.5 text-center text-sm font-medium text-red-50"
    >
        <span>
            A sua conta está em processo de encerramento —
            <strong>{{ account.days_remaining }}</strong> dia(s) para recuperar. Pode exportar os seus dados em
            <a href="/data-exports" class="underline">Exportar dados</a>.
        </span>
        <button
            v-if="account.recoverable"
            type="button"
            class="rounded bg-red-50/10 px-2 py-0.5 text-xs hover:bg-red-50/20"
            @click="reactivateAccount"
        >
            Reativar conta
        </button>
    </div>

    <div
        v-if="organization"
        class="flex flex-wrap items-center justify-center gap-3 bg-red-600 px-4 py-1.5 text-center text-sm font-medium text-red-50"
    >
        <span>
            Esta organização está em processo de encerramento —
            <strong>{{ organization.days_remaining }}</strong> dia(s) para recuperar. Os dados permanecem intactos.
        </span>
        <button
            v-if="organization.is_owner && organization.recoverable"
            type="button"
            class="rounded bg-red-50/10 px-2 py-0.5 text-xs hover:bg-red-50/20"
            @click="reactivateOrganization"
        >
            Reativar organização
        </button>
    </div>
</template>
