<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * «O suporte respondeu-lhe» — a ponta que faltava ao circuito de reporte.
 *
 * SEM COLUNA DE LEITURA, DE PROPÓSITO: o estado `waiting_for_user` já diz
 * exactamente isto — houve resposta e a vez é do professor — e desliga-se
 * sozinho quando ele responde. Uma faixa que insiste enquanto a resposta
 * espera por ele não é ruído; é o estado verdadeiro do pedido. Some ao
 * responder, não ao olhar.
 *
 * Informativa e discreta (azul, uma linha), ao contrário da faixa de
 * encerramento de conta, que é vermelha porque ali perde-se dados.
 */
const page = usePage();

const awaiting = computed(() => Number(page.props.supportAwaitingReply ?? 0));
</script>

<template>
    <div
        v-if="awaiting > 0"
        class="flex flex-wrap items-center justify-center gap-2 bg-blue-600 px-4 py-1.5 text-center text-sm font-medium text-blue-50"
        data-support-reply-banner
    >
        <span v-if="awaiting === 1">O suporte respondeu a um pedido seu.</span>
        <span v-else>O suporte respondeu a {{ awaiting }} pedidos seus.</span>
        <Link href="/support" class="underline">Ver a resposta</Link>
    </div>
</template>
