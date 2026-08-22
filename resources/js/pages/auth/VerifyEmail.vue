<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'Verificação de e-mail',
        description:
            'Confirme o seu endereço de e-mail clicando na ligação que acabámos de lhe enviar.',
    },
});

defineProps<{
    status?: string;
    sendFailed?: boolean;
}>();
</script>

<template>
    <Head title="Verificação de e-mail" />

    <div
        v-if="sendFailed"
        class="mb-4 text-center text-sm font-medium text-amber-600"
    >
        A conta foi criada, mas não foi possível enviar o email de verificação
        neste momento. Pode tentar reenviá-lo dentro de instantes.
    </div>
    <div
        v-else-if="status === 'verification-link-sent'"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        Foi enviada uma nova ligação de verificação para o endereço de e-mail
        que indicou no registo.
    </div>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            Enviar novamente
        </Button>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            Terminar sessão
        </TextLink>
    </Form>
</template>
