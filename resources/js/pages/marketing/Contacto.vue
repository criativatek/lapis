<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import MarketingShell from '@/components/marketing/MarketingShell.vue';
import PageHero from '@/components/marketing/PageHero.vue';
import { Button } from '@/components/ui/button';

/**
 * /contacto — falar connosco sem ter conta.
 *
 * DEPOIS DE ENVIAR, A PESSOA RECEBE A REFERÊNCIA E MAIS NADA: não há link para
 * um portal, não há «consultar pedido». Um visitante não consulta o histórico —
 * dar-lhe essa porta obrigaria a tratar o endereço de email como credencial
 * durante toda a vida do pedido (ADR-0011 §3). A referência serve para nos
 * dizer, não para entrar.
 */

defineProps<{
    categories: { value: string; label: string }[];
    seoTitle: string;
    contactEmail: string | null;
}>();

const page = usePage();

type SupportFlash = {
    supportReference?: string;
    supportEmailDelivered?: boolean;
};

/**
 * `page.flash`, NÃO `page.props.flash`.
 *
 * A flash do Inertia viaja ao lado dos props no objeto da página, não dentro
 * deles — `usePage()` expõe-na em `flash`. Lida do sítio errado é sempre
 * `undefined`, e este painel de confirmação nunca chegava a aparecer.
 */
const flash = computed(() => (page.flash as SupportFlash | undefined) ?? {});

/** A referência devolvida pelo servidor, quando o envio correu bem. */
const reference = computed(() => flash.value.supportReference ?? null);

/**
 * A confirmação por email chegou a sair?
 *
 * O pedido fica registado de qualquer maneira — é o email que pode ter
 * falhado, e o ecrã tem de o dizer. Para um visitante isto pesa mais do que
 * para quem tem conta: sem portal, o email é o único sítio onde ele voltaria a
 * ver este pedido, e se não saiu a referência no ecrã passa a ser tudo o que
 * ele tem.
 */
const emailDelivered = computed(
    () => flash.value.supportEmailDelivered === true,
);

const form = useForm({
    requester_name: '',
    requester_email: '',
    category: '',
    subject: '',
    description: '',
});

function submit(): void {
    form.post('/contacto', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head :title="seoTitle" />

    <MarketingShell v-slot="{ authenticated }" :contact-email="contactEmail">
        <PageHero
            eyebrow="Contacto"
            title="Fale connosco"
            lead="Escreva-nos e respondemos por email. Não precisa de ter conta — e se já tiver, pode abrir o pedido dentro do Lapispro para acompanhar as respostas."
            :image="{
                src: '/images/marketing/teacher-laptop.webp',
                alt: 'Professora a escrever no computador portátil.',
            }"
            :authenticated="authenticated"
        />

        <section class="mx-auto w-full max-w-2xl px-6 pb-20 sm:px-8">
            <div
                v-if="reference"
                class="rounded-2xl border border-border bg-card p-6"
            >
                <h2 class="text-lg font-semibold tracking-tight">
                    Pedido enviado com sucesso
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    O seu pedido
                    <strong class="font-mono">{{ reference }}</strong> foi
                    recebido pela equipa de suporte. Lamentamos o incómodo e
                    iremos analisá-lo com a maior brevidade possível.
                </p>
                <p
                    v-if="emailDelivered"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    Enviámos também uma confirmação para o seu email. Se
                    responder a essa mensagem, ela chega à nossa caixa de
                    suporte — mas não fica guardada no histórico do pedido.
                </p>
                <p v-else class="mt-2 text-sm text-muted-foreground">
                    A confirmação por email não pôde ser enviada neste momento,
                    mas o pedido ficou registado normalmente. Guarde esta
                    referência: é por ela que o identificamos.
                </p>
            </div>

            <form v-else class="space-y-4" @submit.prevent="submit">
                <div
                    class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"
                >
                    Não inclua nomes de alunos, dados de saúde ou outros dados
                    pessoais desnecessários no pedido de suporte.
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Nome</span>
                        <input
                            v-model="form.requester_name"
                            type="text"
                            autocomplete="name"
                            maxlength="120"
                            class="w-full rounded-md border border-border bg-background px-3 py-2"
                        />
                        <InputError :message="form.errors.requester_name" />
                    </label>

                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Email</span>
                        <input
                            v-model="form.requester_email"
                            type="email"
                            autocomplete="email"
                            maxlength="255"
                            class="w-full rounded-md border border-border bg-background px-3 py-2"
                        />
                        <InputError :message="form.errors.requester_email" />
                    </label>
                </div>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium">Assunto</span>
                    <select
                        v-model="form.category"
                        class="w-full rounded-md border border-border bg-background px-3 py-2"
                    >
                        <option value="" disabled>Escolha uma opção</option>
                        <option
                            v-for="option in categories"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <InputError :message="form.errors.category" />
                </label>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium">Resumo</span>
                    <input
                        v-model="form.subject"
                        type="text"
                        maxlength="200"
                        class="w-full rounded-md border border-border bg-background px-3 py-2"
                    />
                    <InputError :message="form.errors.subject" />
                </label>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium">Descrição</span>
                    <textarea
                        v-model="form.description"
                        rows="8"
                        maxlength="5000"
                        class="w-full rounded-md border border-border bg-background px-3 py-2"
                    ></textarea>
                    <InputError :message="form.errors.description" />
                </label>

                <!--
                    A informação curta que a lei exige no momento da recolha,
                    imediatamente antes do botão e não escondida num rodapé.

                    NÃO HÁ CAIXA DE ACEITAÇÃO, e é deliberado: o tratamento não
                    assenta em consentimento — assenta em diligências
                    pré-contratuais, na execução do contrato ou em interesse
                    legítimo, consoante o motivo do contacto. Pedir «aceito a
                    Política» daria a entender um consentimento que não é a base
                    usada, e que a pessoa não poderia retirar sem deixar de
                    poder ser respondida.
                -->
                <p
                    class="border-t border-border pt-4 text-sm leading-relaxed text-muted-foreground"
                >
                    Usamos os dados que nos indicar para analisar e responder ao
                    seu pedido. Consoante o motivo do contacto, o tratamento
                    assenta em diligências pré-contratuais, na prestação do
                    serviço, ou no nosso interesse legítimo em gerir os
                    contactos que nos são dirigidos e manter a segurança do
                    suporte. As finalidades, os prazos de conservação e os seus
                    direitos estão na
                    <a href="/privacidade" class="underline"
                        >Política de Privacidade</a
                    >.
                </p>

                <Button type="submit" :disabled="form.processing">
                    Enviar pedido
                </Button>
            </form>
        </section>
    </MarketingShell>
</template>
