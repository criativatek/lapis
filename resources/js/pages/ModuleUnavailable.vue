<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import { Button } from '@/components/ui/button';

/**
 * O que um professor vê ao abrir uma funcionalidade que o plano da organização
 * não inclui. Continua a ser um 403 no servidor (RequireModule); aqui só deixa
 * de parecer uma avaria. «Ver planos» só para quem pode mudar o plano — um
 * membro de uma organização institucional não pode.
 */
defineProps<{ canManagePlan: boolean }>();
</script>

<template>
    <Head title="Funcionalidade não incluída" />

    <div class="mx-auto w-full max-w-2xl p-4">
        <EmptyState
            :icon="Lock"
            title="Esta funcionalidade não está incluída no plano atual."
            :description="
                canManagePlan
                    ? 'Pode ver o que cada plano inclui e mudar de plano quando quiser.'
                    : 'Se precisar dela, fale com o responsável da sua organização.'
            "
        >
            <template #action>
                <div class="flex flex-wrap justify-center gap-2">
                    <Button as-child variant="outline">
                        <Link href="/dashboard">Voltar ao painel</Link>
                    </Button>
                    <Button v-if="canManagePlan" as-child>
                        <Link href="/settings/plan">Ver planos</Link>
                    </Button>
                </div>
            </template>
        </EmptyState>
    </div>
</template>
