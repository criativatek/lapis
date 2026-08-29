<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
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
defineProps<{
    plans: LandingPlan[];
    seoTitle: string;
    contactEmail: string | null;
}>();
</script>

<template>
    <Head :title="seoTitle" />

    <MarketingShell v-slot="{ authenticated }" :contact-email="contactEmail">
        <PageHero
            eyebrow="Planos"
            title="Comece de graça."
            title-accent="Suba quando o ano pedir mais."
            lead="O plano Base é gratuito e fica ativo sem cartão. O Pro custa 44,90 € por ano — menos de 3,75 € por mês — e os primeiros 250 professores têm a condição Membro Fundador. Escolas e agrupamentos falam connosco."
            :image="{
                src: '/images/marketing/planning.webp',
                alt: 'Mesa vista de cima com um planificador semanal, caneta e café.',
            }"
            :authenticated="authenticated"
            cta="Começar gratuitamente"
            :chips="[
                'Sem cartão',
                'Sem pagamento mensal',
                'Membro Fundador até 250',
            ]"
        />

        <LandingPricing
            :plans="plans"
            :authenticated="authenticated"
            :contact-email="contactEmail"
            headless
        />
        <LandingCompare :plans="plans" />
        <LandingVoucher />
        <LandingFaq />
        <LandingFinalCta :authenticated="authenticated" />
    </MarketingShell>
</template>
