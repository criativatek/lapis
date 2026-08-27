<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import AccountClosure from '@/components/AccountClosure.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import OrganizationMembership from '@/components/OrganizationMembership.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Definições de perfil',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Definições de perfil" />

    <h1 class="sr-only">Definições de perfil</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Perfil"
            description="Alterar o seu nome e endereço de email"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Nome</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Nome completo"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Endereço de email</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Endereço de email"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    O seu endereço de email não está verificado.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        Clique aqui para reenviar o email de verificação.
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    Foi enviada uma nova ligação de verificação para o seu endereço de email.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Guardar</Button
                >
            </div>
        </Form>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-lg border border-border p-4 text-sm">
                <Link href="/data-exports" class="font-medium text-primary hover:underline">Exportar os meus dados</Link>
                <p class="mt-1 text-muted-foreground">Uma cópia dos dados a que a sua conta tem acesso.</p>
            </div>
            <div class="rounded-lg border border-border p-4 text-sm">
                <Link href="/data-imports/create" class="font-medium text-primary hover:underline">Importar dados</Link>
                <p class="mt-1 text-muted-foreground">Restaure dados a partir de uma exportação criada pelo Lapispro.</p>
            </div>
        </div>
    </div>

    <OrganizationMembership />

    <AccountClosure />
</template>
