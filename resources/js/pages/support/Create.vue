<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { maskRoute } from '@/lib/routeMask';

/**
 * A rota de onde a pessoa veio, mascarada.
 *
 * Antes ia o `pathname` inteiro, ULIDs incluídos, e ficava assim numa coluna
 * que um operador lê. O servidor mascara-a de qualquer maneira
 * (`App\Support\Support\RouteMask`); isto é para o ecrã não dizer uma coisa e
 * a base guardar outra.
 */
function referrerRoute(): string | null {
    try {
        return document.referrer ? maskRoute(new URL(document.referrer).pathname) : null;
    } catch {
        return null;
    }
}

/**
 * Abrir um pedido, de dentro da aplicação.
 *
 * NÃO PEDE NOME NEM EMAIL: vêm da conta, no servidor. A rota do ecrã anterior
 * viaja como contexto técnico — MASCARADA, porque o nome do ecrã responde a
 * «onde é que a pessoa estava» e o ULID que lá vai pelo meio identifica uma
 * turma ou uma criança. É tudo o que se recolhe automaticamente: nada de
 * ficheiros, nada de dados dos alunos, nada de stack traces.
 *
 * O AVISO DE MINIMIZAÇÃO ESTÁ VISÍVEL, e não escondido num rodapé. Um pedido de
 * suporte é o texto onde é mais fácil escrever o nome de um aluno sem pensar.
 */

defineProps<{ categories: { value: string; label: string }[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Suporte', href: '/support' },
            { title: 'Novo pedido', href: '/support/novo' },
        ],
    },
});

const form = useForm({
    category: '',
    subject: '',
    description: '',
    technical_route: referrerRoute(),
    technical_reference: null as string | null,
});

function submit(): void {
    form.post('/support', { preserveScroll: true });
}
</script>

<template>
    <Head title="Novo pedido de suporte" />

    <div class="max-w-2xl space-y-6">
        <Heading
            variant="small"
            title="Novo pedido"
            description="Descreva o que precisa. Respondemos por email e o pedido fica aqui."
        />

        <div
            class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950/40"
        >
            Não inclua nomes de alunos, dados de saúde ou outros dados pessoais
            desnecessários no pedido de suporte.
        </div>

        <form class="space-y-4" @submit.prevent="submit">
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

            <Button type="submit" :disabled="form.processing">
                Enviar pedido
            </Button>
        </form>
    </div>
</template>
