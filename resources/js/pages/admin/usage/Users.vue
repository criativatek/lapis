<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { UsageUsersProps } from '@/types/usage';

// Página provisória: o contrato das props está em @/types/usage; a interface
// final vem na fatia seguinte.
defineProps<UsageUsersProps>();
</script>

<template>
    <Head title="Utilização — Acompanhamento" />

    <div class="space-y-4 p-6">
        <h1 class="text-xl font-semibold tracking-tight">Utilização — Acompanhamento</h1>
    </div>
</template>
