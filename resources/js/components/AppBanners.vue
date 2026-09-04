<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ClosureBanners from '@/components/ClosureBanners.vue';
import PrivacyNotice from '@/components/PrivacyNotice.vue';
import SupportReplyBanner from '@/components/SupportReplyBanner.vue';

/**
 * A pilha de faixas do topo — DENTRO da coluna de conteúdo, nunca fora dela.
 *
 * O SUP-8Y3Q5Y mostrou porquê: a sidebar é `fixed inset-y-0 h-svh` (variante
 * inset do shadcn), portanto uma faixa montada FORA do shell fica com a ponta
 * esquerda debaixo da sidebar e empurra um shell de altura de viewport para
 * fora do ecrã — a «faixa azul por baixo» do reporte era o próprio banner a
 * transbordar. Dentro do `AppContent` nada disto existe: a faixa ocupa a
 * largura da coluna, empurra o header como qualquer conteúdo, e a sidebar
 * nunca lhe passa por cima.
 *
 * Ordem deliberada: acesso técnico (âmbar, o mais grave — alguém está dentro
 * da conta) → privacidade → encerramento (vermelho) → resposta do suporte
 * (azul, informativa).
 */
const impersonating = computed(() => usePage().props.impersonating as { name?: string } | null);

function stopImpersonating(): void {
    router.post('/impersonate/stop');
}
</script>

<template>
    <div
        v-if="impersonating"
        class="flex items-center justify-center gap-3 bg-amber-500 px-4 py-1.5 text-center text-sm font-medium text-amber-950"
    >
        <span>Acesso técnico ativo — a ver como <strong>{{ impersonating.name }}</strong></span>
        <button type="button" class="rounded bg-amber-950/10 px-2 py-0.5 text-xs hover:bg-amber-950/20" @click="stopImpersonating">
            Terminar acesso
        </button>
    </div>
    <PrivacyNotice />
    <ClosureBanners />
    <SupportReplyBanner />
</template>
