<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { describePasswordRules } from '@/lib/passwordRules';
import { update } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Definir nova palavra-passe',
        description: 'Escreva abaixo a palavra-passe que passa a usar',
    },
});

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
}>();

const inputEmail = ref(props.email);

// Shown before the teacher submits, not discovered one rejection at a time.
const requirements = computed(() => describePasswordRules(props.passwordRules));
</script>

<template>
    <Head title="Definir nova palavra-passe" />

    <Form
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token, email })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    v-model="inputEmail"
                    class="mt-1 block w-full"
                    readonly
                />
                <InputError :message="errors.email" class="mt-2" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Nova palavra-passe</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    autofocus
                    placeholder="Nova palavra-passe"
                    :passwordrules="passwordRules"
                    aria-describedby="password-requirements"
                />
                <ul
                    v-if="requirements.length"
                    id="password-requirements"
                    class="mt-1 space-y-0.5 text-xs text-muted-foreground"
                >
                    <li v-for="requirement in requirements" :key="requirement">
                        {{ requirement }}
                    </li>
                </ul>
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirmar palavra-passe</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    placeholder="Repita a palavra-passe"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :disabled="processing"
                data-test="reset-password-button"
            >
                <Spinner v-if="processing" />
                Guardar palavra-passe
            </Button>
        </div>
    </Form>
</template>
