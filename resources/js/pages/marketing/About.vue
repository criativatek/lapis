<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Building2, Mail, ShieldCheck } from '@lucide/vue';
import LandingFinalCta from '@/components/landing/LandingFinalCta.vue';
import ColorBand from '@/components/marketing/ColorBand.vue';
import MarketingShell from '@/components/marketing/MarketingShell.vue';
import PageHero from '@/components/marketing/PageHero.vue';

/**
 * /sobre — who is behind the product and how to reach them. The entity is
 * the same the legal pages name (LegalDocuments::controller()), so a school
 * comparing the two finds one answer. Nothing here is invented: no team
 * photos of people who do not exist, no «X professores» that nobody counted.
 */
defineProps<{
    entity: {
        name: string | null;
        vat: string | null;
        address: string | null;
        privacyEmail: string | null;
        supportEmail: string | null;
    };
    contactEmail: string | null;
}>();

const principles = [
    {
        title: 'O professor decide',
        body: 'O Lapispro calcula, propõe e organiza. Nenhuma classificação é atribuída, alterada ou decidida por um modelo ou por uma regra escondida.',
    },
    {
        title: 'Vazio nunca é zero',
        body: 'Uma célula por preencher não é uma nota. Parece um detalhe; é a diferença entre um aluno prejudicado e um aluno avaliado.',
    },
    {
        title: 'Feito em Portugal, para o sistema português',
        body: 'Períodos e semestres, escalas de 1 a 5 e de 0 a 20, a pauta do Intuitivo, o calendário da escola. Não é uma tradução.',
    },
] as const;
</script>

<template>
    <Head title="Sobre e contacto | Lapispro" />

    <MarketingShell v-slot="{ authenticated }" :contact-email="contactEmail">
        <PageHero
            eyebrow="Sobre o Lapispro"
            title="Mais tempo para ensinar. Foi por isso que começámos."
            lead="O Lapispro nasceu de uma pergunta simples: porque é que a avaliação de alunos vive espalhada por folhas de cálculo que só quem as fez sabe ler? A resposta é uma plataforma feita para professores portugueses, com regras pedagógicas claras e o professor sempre no comando."
            :image="{
                src: '/images/marketing/planning.webp',
                alt: 'Mesa vista de cima com um planificador semanal, caneta e café.',
            }"
            :authenticated="authenticated"
            tone="blue"
        />

        <ColorBand
            tone="amber"
            eyebrow="Princípios"
            title="Três coisas de que não abdicamos."
        >
            <div class="grid gap-5 lg:grid-cols-3">
                <div
                    v-for="principle in principles"
                    :key="principle.title"
                    class="rounded-3xl bg-white p-7 shadow-sm ring-1 ring-amber-900/5"
                >
                    <h3
                        class="text-xl font-semibold tracking-tight text-balance"
                    >
                        {{ principle.title }}
                    </h3>
                    <p class="mt-3 leading-relaxed text-muted-foreground">
                        {{ principle.body }}
                    </p>
                </div>
            </div>
        </ColorBand>

        <ColorBand id="contacto" eyebrow="Contacto" title="Fale connosco.">
            <div class="grid gap-5 lg:grid-cols-3">
                <div class="rounded-3xl bg-blue-600 p-7 text-white">
                    <Mail aria-hidden="true" class="size-6" />
                    <h3 class="mt-5 text-lg font-semibold">
                        Perguntas e escolas
                    </h3>
                    <p class="mt-2 text-sm leading-relaxed text-blue-100">
                        Para conhecer o Lapispro, pedir uma demonstração ou
                        falar sobre o plano Institucional.
                    </p>
                    <a
                        v-if="contactEmail ?? entity.supportEmail"
                        :href="`mailto:${contactEmail ?? entity.supportEmail}`"
                        class="mt-5 inline-block rounded font-medium underline underline-offset-4 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                        >{{ contactEmail ?? entity.supportEmail }}</a
                    >
                </div>
                <div
                    class="rounded-3xl bg-white p-7 shadow-sm ring-1 ring-black/5"
                >
                    <ShieldCheck
                        aria-hidden="true"
                        class="size-6 text-emerald-700"
                    />
                    <h3 class="mt-5 text-lg font-semibold">
                        Privacidade e dados
                    </h3>
                    <p
                        class="mt-2 text-sm leading-relaxed text-muted-foreground"
                    >
                        Pedidos de acesso, retificação ou apagamento, e tudo o
                        que diga respeito ao tratamento de dados.
                    </p>
                    <a
                        v-if="entity.privacyEmail"
                        :href="`mailto:${entity.privacyEmail}`"
                        class="mt-5 inline-block rounded font-medium text-foreground underline underline-offset-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >{{ entity.privacyEmail }}</a
                    >
                </div>
                <div
                    class="rounded-3xl bg-white p-7 shadow-sm ring-1 ring-black/5"
                >
                    <Building2
                        aria-hidden="true"
                        class="size-6 text-blue-700"
                    />
                    <h3 class="mt-5 text-lg font-semibold">
                        Entidade responsável
                    </h3>
                    <template v-if="entity.name">
                        <p class="mt-2 text-sm leading-relaxed">
                            <span class="font-medium">{{ entity.name }}</span>
                            <template v-if="entity.vat">
                                <br />NIF {{ entity.vat }}
                            </template>
                            <template v-if="entity.address">
                                <br />{{ entity.address }}
                            </template>
                        </p>
                    </template>
                    <p
                        v-else
                        class="mt-2 text-sm leading-relaxed text-muted-foreground"
                    >
                        A identidade do responsável pelo tratamento consta da
                        Política de Privacidade.
                    </p>
                </div>
            </div>
        </ColorBand>

        <LandingFinalCta :authenticated="authenticated" />
    </MarketingShell>
</template>
