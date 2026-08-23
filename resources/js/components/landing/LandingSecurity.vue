<script setup lang="ts">
import { KeyRound, Layers, LockKeyhole, ScrollText } from '@lucide/vue';
import type { Component } from 'vue';
import LandingSection from './LandingSection.vue';
import RevealOnScroll from './RevealOnScroll.vue';

type Guarantee = {
    icon: Component;
    title: string;
    body: string;
};

const guarantees: readonly Guarantee[] = [
    {
        icon: LockKeyhole,
        title: 'A identidade fica separada e protegida',
        body: 'No trabalho diário, o sistema pode utilizar identificadores pseudonimizados. Os dados de identificação permanecem separados e protegidos.',
    },
    {
        icon: Layers,
        title: 'Cada conta acede apenas ao contexto autorizado',
        body: 'O isolamento é aplicado no servidor e nas regras de acesso.',
    },
    {
        icon: KeyRound,
        title: 'Proteção adicional no acesso à conta',
        body: 'Autenticação em dois passos, códigos de recuperação e passkeys quando disponíveis.',
    },
    {
        icon: ScrollText,
        title: 'As ações importantes ficam registadas',
        body: 'As operações relevantes ficam associadas a quem as realizou, permitindo auditoria pelos responsáveis autorizados.',
    },
];
</script>

<template>
    <LandingSection
        id="seguranca"
        tinted
        eyebrow="Dados e privacidade"
        title="Dados de alunos exigem proteção desde a origem."
        lead="A informação académica e pessoal está entre os dados mais sensíveis tratados pelo LÁPIS. A proteção faz parte da arquitetura do sistema, não é um acrescento posterior. Esta proteção é particularmente relevante quando estão envolvidos menores, mas aplica-se a todos os alunos e estudantes."
    >
        <div
            class="grid gap-12 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] lg:gap-16"
        >
            <dl class="space-y-7">
                <RevealOnScroll
                    v-for="(guarantee, index) in guarantees"
                    :key="guarantee.title"
                    :delay="index * 70"
                >
                    <div class="group flex gap-4">
                        <span
                            aria-hidden="true"
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-border/70 bg-card text-primary shadow-sm transition-all duration-500 group-hover:border-primary/40 group-hover:shadow-md motion-safe:group-hover:-translate-y-0.5 dark:text-(--brand-amber) dark:group-hover:border-(--brand-amber)/40"
                        >
                            <component
                                :is="guarantee.icon"
                                class="size-[18px]"
                            />
                        </span>
                        <div class="min-w-0">
                            <dt class="font-medium tracking-tight text-balance">
                                {{ guarantee.title }}
                            </dt>
                            <dd
                                class="mt-1.5 text-sm leading-relaxed text-muted-foreground"
                            >
                                {{ guarantee.body }}
                            </dd>
                        </div>
                    </div>
                </RevealOnScroll>
            </dl>

            <RevealOnScroll :delay="120">
                <figure
                    class="rounded-2xl border border-border/70 bg-card p-6 shadow-sm"
                >
                    <figcaption
                        class="text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                    >
                        Como os dados ficam separados
                    </figcaption>

                    <div class="mt-5 space-y-3">
                        <div class="rounded-xl border border-border/70 p-4">
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                O que a aplicação usa no dia a dia
                            </p>
                            <dl
                                class="mt-3 space-y-1.5 font-mono text-[13px] tabular-nums"
                            >
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        contexto académico
                                    </dt>
                                    <dd>Turma / Unidade curricular</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        identificador
                                    </dt>
                                    <dd>A-1042</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        classificação
                                    </dt>
                                    <dd>4</dd>
                                </div>
                            </dl>
                        </div>

                        <div
                            class="rounded-xl border border-emerald-200/70 bg-emerald-50/50 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/20"
                        >
                            <p
                                class="flex items-center gap-2 text-xs font-medium text-emerald-800 dark:text-emerald-300"
                            >
                                <LockKeyhole
                                    aria-hidden="true"
                                    class="size-3.5"
                                />
                                Identidade protegida e separada
                            </p>
                            <dl
                                class="mt-3 space-y-1.5 font-mono text-[13px] tabular-nums"
                            >
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">nome</dt>
                                    <dd class="tracking-widest">••••••••••</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        identificador institucional
                                    </dt>
                                    <dd class="tracking-widest">••••••</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <p
                        class="mt-5 border-t border-border/70 pt-4 text-xs leading-relaxed text-muted-foreground"
                    >
                        O apoio à redação está desligado por omissão. Quando é
                        ativado, a configuração fica sob controlo do titular da
                        conta ou, nas contas institucionais, dos responsáveis
                        autorizados. A informação enviada é pseudonimizada.
                    </p>
                </figure>
            </RevealOnScroll>
        </div>

        <RevealOnScroll>
            <p
                class="mt-12 max-w-3xl text-sm leading-relaxed text-muted-foreground"
            >
                A conformidade com o RGPD não depende apenas da tecnologia. O
                LÁPIS incorpora medidas técnicas de proteção, incluindo
                separação de dados, cifragem, isolamento entre contas e
                registo de operações.
            </p>
        </RevealOnScroll>
    </LandingSection>
</template>
