<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { UsageUserDetailProps } from '@/types/usage';

// Página provisória: o contrato das props está em @/types/usage; a interface
// final vem na fatia seguinte.
defineProps<UsageUserDetailProps>();
</script>

<template>
    <Head title="Utilização — Pessoa" />

    <div class="space-y-4 p-6">
        <h1 class="text-xl font-semibold tracking-tight">Utilização — Pessoa</h1>
    </div>
</template>
