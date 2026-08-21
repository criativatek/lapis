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
        title: 'A identidade do aluno vive à parte, cifrada',
        body: 'Numa tabela própria e cifrada. O resto da aplicação usa um pseudónimo.',
    },
    {
        icon: Layers,
        title: 'Cada organização só vê o que é seu',
        body: 'Imposto no servidor, em todas as consultas. Nunca devolve tudo por engano.',
    },
    {
        icon: KeyRound,
        title: 'Dois passos e chaves de acesso',
        body: 'Dois passos com códigos de recuperação, ou passkey.',
    },
    {
        icon: ScrollText,
        title: 'Fica registado quem fez o quê',
        body: 'Consultável por quem é responsável pela organização.',
    },
];
</script>

<template>
    <LandingSection
        id="seguranca"
        tinted
        eyebrow="Confiança"
        title="Isto são dados de menores. A proteção é estrutural, não um acabamento."
        lead="A informação sobre um aluno é a parte mais sensível do sistema. Foi tratada como tal desde a primeira migração."
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
                                    <dt class="text-muted-foreground">turma</dt>
                                    <dd>9.º B</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        pseudónimo
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
                                Cifrado, e noutra tabela
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
                                        n.º de processo
                                    </dt>
                                    <dd class="tracking-widest">••••••</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <p
                        class="mt-5 border-t border-border/70 pt-4 text-xs leading-relaxed text-muted-foreground"
                    >
                        O apoio à redação está desligado por omissão. Quando uma
                        escola o liga, escolhe o motor — e o que sai é o
                        pseudónimo, nunca o nome.
                    </p>
                </figure>
            </RevealOnScroll>
        </div>

        <RevealOnScroll>
            <p
                class="mt-12 max-w-3xl text-sm leading-relaxed text-muted-foreground"
            >
                A conformidade com o RGPD também depende das políticas da sua
                escola. O que o LÁPIS garante é a parte técnica — separação,
                cifra, isolamento e rasto.
            </p>
        </RevealOnScroll>
    </LandingSection>
</template>
