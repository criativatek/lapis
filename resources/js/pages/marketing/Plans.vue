<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import LandingCompare from '@/components/landing/LandingCompare.vue';
import LandingFaq from '@/components/landing/LandingFaq.vue';
import LandingFinalCta from '@/components/landing/LandingFinalCta.vue';
import LandingPricing from '@/components/landing/LandingPricing.vue';
import LandingVoucher from '@/components/landing/LandingVoucher.vue';
import type { LandingPlan } from '@/components/landing/types';
import MarketingShell from '@/components/marketing/MarketingShell.vue';
import PageHero from '@/components/marketing/PageHero.vue';

/**
 * /planos — the three plans, the Fundador condition, the comparison, the
 * voucher and the questions people ask before paying. The pricing section
 * is the same component the landing used, so the two never quote two prices.
 */
const props = defineProps<{
    plans: LandingPlan[];
    founder: { open: boolean };
    seoTitle: string;
    contactEmail: string | null;
}>();

/**
 * THE FIRST SCREEN MAY NOT PROMISE WHAT THE REST OF THE PAGE RETRACTS. The
 * badge, the Fundador band and the FAQ answer are gated on `founder.open`
 * (the product lineage's fix: offering 29,90 € to somebody who can no longer
 * have it was the one materially false sentence this page could say). The
 * hero has to be gated on the same flag, or the promise survives above the
 * fold after the seats or the deadline run out.
 */
const lead = computed(() =>
    props.founder.open
        ? 'O plano Base é gratuito e fica ativo sem cartão. O Pro custa 44,90 € por ano — menos de 3,75 € por mês — e os primeiros 250 professores têm a condição Membro Fundador. Escolas e agrupamentos falam connosco.'
        : 'O plano Base é gratuito e fica ativo sem cartão. O Pro custa 44,90 € por ano — menos de 3,75 € por mês, em subscrição anual. Escolas e agrupamentos falam connosco.',
);

const chips = computed(() =>
    props.founder.open
        ? ['Sem cartão', 'Sem pagamento mensal', 'Membro Fundador até 250']
        : ['Sem cartão', 'Sem pagamento mensal', 'Cancela quando quiser'],
);
</script>

<template>
    <Head :title="seoTitle" />

    <MarketingShell v-slot="{ authenticated }" :contact-email="contactEmail">
        <PageHero
            eyebrow="Planos"
            title="Comece de graça."
            title-accent="Suba quando o ano pedir mais."
            :lead="lead"
            :image="{
                src: '/images/marketing/planning.webp',
                alt: 'Mesa vista de cima com um planificador semanal, caneta e café.',
            }"
            :authenticated="authenticated"
            cta="Começar gratuitamente"
            :chips="chips"
        />

        <LandingPricing
            :plans="plans"
            :authenticated="authenticated"
            :contact-email="contactEmail"
            :founder-open="founder.open"
            headless
        />
        <LandingCompare :plans="plans" />
        <LandingVoucher />
        <LandingFaq :founder-open="founder.open" />
        <LandingFinalCta :authenticated="authenticated" />
    </MarketingShell>
</template>
