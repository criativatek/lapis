<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

/**
 * «Não registar a minha utilização» (docs/usage-insights.md, «Direito de
 * oposição»). O servidor só envia `usageMetrics` quando a Política descreve as
 * métricas (a recolha já foi ativada alguma vez) ou quando a pessoa já se opôs;
 * fora disso a secção não existe.
 *
 * Opor-se não pede confirmação: é um direito, e o efeito — parar a recolha e
 * eliminar as métricas — está escrito por baixo, antes do clique. Nada mais
 * muda na conta.
 */
type UsageMetrics = {
    opted_out: boolean;
    opted_out_at: string | null;
    resumed_at: string | null;
    collecting: boolean;
};

const page = usePage();
const metrics = computed(
    () => page.props.usageMetrics as UsageMetrics | null | undefined,
);
const saving = ref(false);

function formatDate(iso: string | null): string {
    return iso === null ? '' : new Date(iso).toLocaleDateString('pt-PT');
}

function update(value: boolean | 'indeterminate'): void {
    if (value === 'indeterminate' || saving.value) {
        return;
    }

    saving.value = true;
    router.put(
        '/settings/usage-metrics',
        { opted_out: value },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}
</script>

<template>
    <div v-if="metrics" id="metricas-de-utilizacao" class="space-y-6" data-test="usage-metrics-preference">
        <Heading
            variant="small"
            title="Métricas de utilização"
            description="Contagens de que funcionalidades usa e de quantas ações conclui, nunca o conteúdo do seu trabalho."
        />

        <div class="space-y-4 rounded-lg border border-border p-4">
            <div class="flex items-start gap-3">
                <Checkbox
                    id="usage-opt-out"
                    class="mt-0.5"
                    :model-value="metrics.opted_out"
                    :disabled="saving"
                    data-test="usage-opt-out"
                    @update:model-value="update"
                />
                <div class="space-y-1">
                    <Label for="usage-opt-out" class="font-medium">Não registar a minha utilização</Label>
                    <p class="text-sm text-muted-foreground">
                        Ao fazê-lo, a recolha é interrompida e as métricas associadas à sua conta são eliminadas, sem afetar o
                        acesso às funcionalidades do Lapispro. Se voltar a permitir a recolha, ela recomeça a partir desse
                        momento, sem recuperar o que foi eliminado.
                    </p>
                </div>
            </div>

            <p class="text-sm" data-test="usage-metrics-state">
                <template v-if="metrics.opted_out">
                    Desde {{ formatDate(metrics.opted_out_at) }}, a sua utilização não é registada.
                </template>
                <template v-else-if="!metrics.collecting">
                    A recolha de métricas de utilização está neste momento desligada no Lapispro.
                </template>
                <template v-else-if="metrics.resumed_at">
                    A sua utilização é registada desde {{ formatDate(metrics.resumed_at) }}.
                </template>
                <template v-else>A sua utilização está a ser registada.</template>
                <a href="/privacidade" class="ml-1 underline">Saber mais na Política de Privacidade</a>
            </p>
        </div>
    </div>
</template>
