<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import LandingCompare from '@/components/landing/LandingCompare.vue';
import LandingFaq from '@/components/landing/LandingFaq.vue';
import LandingFinalCta from '@/components/landing/LandingFinalCta.vue';
import LandingPricing from '@/components/landing/LandingPricing.vue';
import LandingVoucher from '@/components/landing/LandingVoucher.vue';
import type { LandingPlan } from '@/components/landing/types';
import MarketingShell from '@/components/marketing/MarketingShell.vue';

/**
 * /planos — the three plans, the Fundador condition, the comparison, the
 * voucher and the questions people ask before paying. The pricing section
 * is the same component the landing used, so the two never quote two prices.
 */
defineProps<{ plans: LandingPlan[]; contactEmail: string | null }>();
</script>

<template>
    <Head title="Planos e preços | Lapispro" />

    <MarketingShell v-slot="{ authenticated }" :contact-email="contactEmail">
        <div class="mx-auto w-full max-w-6xl px-6 pt-12 sm:px-8 sm:pt-16">
            <p
                class="inline-flex rounded-full bg-blue-50 px-3.5 py-1.5 text-[12px] font-semibold tracking-[0.08em] text-blue-700 uppercase"
            >
                Planos
            </p>
            <h1
                class="mt-6 max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl lg:text-[3.6rem] lg:leading-[1.04]"
            >
                Comece de graça. Suba quando o ano pedir mais.
            </h1>
            <p
                class="mt-6 max-w-2xl text-lg leading-relaxed text-pretty text-muted-foreground sm:text-xl"
            >
                O plano Base é gratuito e fica ativo sem cartão. O Pro custa
                44,90 € por ano — menos de 3,75 € por mês — e os primeiros 250
                professores têm a condição Membro Fundador. Escolas e
                agrupamentos falam connosco.
            </p>
        </div>

        <LandingPricing
            :plans="plans"
            :authenticated="authenticated"
            :contact-email="contactEmail"
        />
        <LandingCompare :plans="plans" />
        <LandingVoucher />
        <LandingFaq />
        <LandingFinalCta :authenticated="authenticated" />
    </MarketingShell>
</template>
