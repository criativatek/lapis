<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import LandingFooter from '@/components/landing/LandingFooter.vue';
import LandingHeader from '@/components/landing/LandingHeader.vue';

/**
 * Uma página legal — Termos ou Privacidade, o mesmo componente para as duas.
 *
 * O TEXTO NÃO ESTÁ AQUI, e é deliberado: com o SSR do Inertia desligado, texto
 * escrito num `.vue` não chega à resposta e nenhum teste de servidor lhe pode
 * tocar. Vem de `App\Support\Legal\LegalDocuments`, viaja no payload, e é lá
 * que está afirmado por testes. Este ficheiro só decide como se lê.
 *
 * UMA COLUNA ESTREITA, sem cartões nem caixas. Um documento legal legível é um
 * documento legal que alguém chega ao fim de ler — `max-w-3xl` é a medida que
 * o resto da página já usa para prosa longa (ver LandingFinalCta).
 *
 * O cabeçalho e o rodapé são os da landing: quem chega aqui a partir de uma
 * pesquisa deve reconhecer o mesmo sítio, e deve conseguir sair para o produto.
 */

type Section = { heading: string; body: string[] };

type LegalDocument = {
    title: string;
    effective_from: string;
    intro: string;
    sections: Section[];
};

type Controller = {
    name: string | null;
    vat: string | null;
    address: string | null;
    privacy_email: string | null;
    complete: boolean;
};

const props = defineProps<{
    document: LegalDocument;
    controller: Controller;
}>();

const page = usePage();
const authenticated = computed(() => page.props.auth.user !== null);

/** «27 de agosto de 2026», a partir de «2026-08-27». */
const effectiveFrom = computed(() => {
    const parsed = new Date(`${props.document.effective_from}T00:00:00`);

    return Number.isNaN(parsed.getTime())
        ? props.document.effective_from
        : new Intl.DateTimeFormat('pt-PT', {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          }).format(parsed);
});

/**
 * O que falta da identidade do responsável, dito por extenso.
 *
 * Um valor por definir aparece como «por definir» — nunca como um nome
 * plausível. Uma página que existe para ser verdadeira não pode abrir com uma
 * morada inventada.
 */
const identity = computed(() =>
    [
        { label: 'Entidade', value: props.controller.name },
        { label: 'NIF', value: props.controller.vat },
        { label: 'Morada', value: props.controller.address },
        {
            label: 'Contacto para privacidade',
            value: props.controller.privacy_email,
        },
    ].map((row) => ({ ...row, missing: row.value === null })),
);
</script>

<template>
    <Head :title="`${document.title} — Lapispro`" />

    <div class="min-h-screen bg-background text-foreground">
        <a
            href="#documento"
            class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[60] focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-primary-foreground"
        >
            Saltar para o conteúdo
        </a>

        <LandingHeader :authenticated="authenticated" />

        <main
            id="documento"
            class="mx-auto w-full max-w-3xl px-6 py-16 sm:px-8 sm:py-24"
        >
            <article>
                <h1
                    class="text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                >
                    {{ document.title }}
                </h1>

                <p class="mt-3 text-sm text-muted-foreground">
                    Em vigor desde {{ effectiveFrom }}
                </p>

                <p
                    class="mt-6 text-lg leading-relaxed text-pretty text-foreground"
                >
                    {{ document.intro }}
                </p>

                <section
                    v-for="section in document.sections"
                    :key="section.heading"
                    class="mt-12 border-t border-border/70 pt-8"
                >
                    <h2
                        class="text-xl font-semibold tracking-tight text-balance"
                    >
                        {{ section.heading }}
                    </h2>

                    <p
                        v-for="(paragraph, index) in section.body"
                        :key="index"
                        class="mt-4 leading-relaxed text-pretty text-muted-foreground"
                    >
                        {{ paragraph }}
                    </p>
                </section>

                <!-- A identidade fecha o documento, com o que falta dito por
                     extenso em vez de preenchido com um exemplo. -->
                <section class="mt-12 border-t border-border/70 pt-8">
                    <h2 class="text-xl font-semibold tracking-tight">
                        Quem é o responsável
                    </h2>

                    <dl class="mt-4 space-y-2.5 text-sm">
                        <div
                            v-for="row in identity"
                            :key="row.label"
                            class="flex flex-col gap-0.5 sm:flex-row sm:gap-3"
                        >
                            <dt
                                class="shrink-0 font-medium text-foreground sm:w-56"
                            >
                                {{ row.label }}
                            </dt>
                            <dd
                                class="min-w-0 text-pretty"
                                :class="
                                    row.missing
                                        ? 'text-muted-foreground/70 italic'
                                        : 'text-muted-foreground'
                                "
                            >
                                <template v-if="row.missing"
                                    >Por definir</template
                                >
                                <template v-else>{{ row.value }}</template>
                            </dd>
                        </div>
                    </dl>

                    <p
                        v-if="!controller.complete"
                        class="mt-5 rounded-lg border border-border/70 bg-muted/40 p-4 text-sm leading-relaxed text-pretty text-muted-foreground dark:bg-muted/15"
                    >
                        Alguns destes elementos ainda não estão publicados.
                        Serão acrescentados a esta página assim que estiverem
                        definidos.
                    </p>
                </section>
            </article>
        </main>

        <LandingFooter :authenticated="authenticated" />
    </div>
</template>
