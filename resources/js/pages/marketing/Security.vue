<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Database,
    EyeOff,
    FileCheck,
    KeyRound,
    Lock,
    ScrollText,
} from '@lucide/vue';
import LandingFinalCta from '@/components/landing/LandingFinalCta.vue';
import LandingSecurity from '@/components/landing/LandingSecurity.vue';
import BenefitCard from '@/components/marketing/BenefitCard.vue';
import ColorBand from '@/components/marketing/ColorBand.vue';
import MarketingShell from '@/components/marketing/MarketingShell.vue';
import PageHero from '@/components/marketing/PageHero.vue';
import PhotoBand from '@/components/marketing/PhotoBand.vue';

/**
 * /seguranca — the page a school opens before trusting student data to a
 * platform. Says only what the product does (LegalPagesTest keeps the legal
 * text honest; this page repeats its claims, not more).
 */
defineProps<{ contactEmail: string | null }>();

const measures = [
    {
        icon: EyeOff,
        title: 'Pseudonimização desde a origem',
        body: 'O nome de cada aluno fica cifrado e separado. No dia a dia e em qualquer processamento por IA circula só um pseudónimo.',
    },
    {
        icon: Database,
        title: 'Isolamento por organização',
        body: 'Cada conta acede apenas ao seu contexto. O isolamento é aplicado no servidor, em todas as consultas — nunca só no ecrã.',
    },
    {
        icon: KeyRound,
        title: 'Dois fatores e passkeys',
        body: 'Autenticação em dois passos, códigos de recuperação e passkeys. Sessões e dispositivos geridos pelo próprio utilizador.',
    },
    {
        icon: ScrollText,
        title: 'Registo de operações',
        body: 'As ações relevantes ficam associadas a quem as realizou, permitindo auditoria pelos responsáveis autorizados.',
    },
    {
        icon: Lock,
        title: 'IA sem dados identificáveis',
        body: 'A IA pedagógica recebe pseudónimos e resultados, nunca nomes. Não decide classificações e não guarda o que lê.',
    },
    {
        icon: FileCheck,
        title: 'Os dados são seus',
        body: 'Exportação dos próprios dados em todos os planos; cópia de segurança completa e restauro no Pro. Encerrar a conta apaga o que é seu.',
    },
] as const;
</script>

<template>
    <Head title="Segurança e proteção de dados | Lapispro" />

    <MarketingShell v-slot="{ authenticated }" :contact-email="contactEmail">
        <PageHero
            eyebrow="Segurança e dados"
            title="Dados de alunos exigem"
            title-accent="proteção desde a origem."
            lead="A informação académica e pessoal de menores está entre os dados mais sensíveis que existem numa escola. No Lapispro a proteção faz parte da arquitetura, não é um acrescento no fim."
            :image="{
                src: '/images/marketing/classroom.webp',
                alt: 'Sala de aula vazia ao fim da tarde, com luz a entrar pela janela.',
            }"
            :authenticated="authenticated"
            cta="Criar conta gratuita"
        />

        <ColorBand
            id="medidas"
            tone="emerald"
            eyebrow="As medidas"
            title="Seis coisas que o Lapispro faz por defeito."
        >
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <BenefitCard
                    v-for="measure in measures"
                    :key="measure.title"
                    :icon="measure.icon"
                    :title="measure.title"
                    :body="measure.body"
                    tone="emerald"
                />
            </div>
        </ColorBand>

        <LandingSecurity />

        <PhotoBand
            eyebrow="RGPD"
            title="Conformidade não é um selo. É como o sistema está feito."
            body="O responsável pelo tratamento está identificado nos documentos legais, o acordo de tratamento de dados está publicado, e cada medida técnica desta página corresponde a algo que existe no código — não a uma intenção."
            :image="{
                src: '/images/marketing/teacher-back.webp',
                alt: 'Professora de costas a olhar para um quadro com anotações.',
            }"
            flip
        >
            <nav
                aria-label="Documentos legais"
                class="mt-7 flex flex-wrap gap-3"
            >
                <Link
                    v-for="doc in [
                        {
                            label: 'Política de Privacidade',
                            href: '/privacidade',
                        },
                        {
                            label: 'Acordo de Tratamento de Dados',
                            href: '/tratamento-de-dados',
                        },
                        { label: 'Termos de Utilização', href: '/termos' },
                    ]"
                    :key="doc.href"
                    :href="doc.href"
                    class="rounded-full bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-800 ring-1 ring-emerald-900/10 transition-colors hover:bg-emerald-100 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {{ doc.label }}
                </Link>
            </nav>
        </PhotoBand>

        <LandingFinalCta :authenticated="authenticated" />
    </MarketingShell>
</template>
