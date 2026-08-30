<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * «Atualizámos a Política de Privacidade.»
 *
 * INFORMATIVO, NUNCA BLOQUEANTE. Não há botão de aceitar, não há caixa a
 * assinalar e não há nada a consentir: uma Política de Privacidade informa, e
 * pedir aceitação daria a entender um consentimento que não é o fundamento de
 * tratamento nenhum descrito no documento. Fechar apenas carimba a data em que
 * esta pessoa a leu.
 *
 * O SERVIDOR DECIDE SE APARECE. `auth.should_see_privacy_notice` compara a data
 * de entrada em vigor da Política com o carimbo do utilizador, por isso uma
 * actualização futura volta a mostrá-lo sozinha. O `ref` local existe só para
 * a faixa desaparecer no clique, sem esperar pela resposta.
 */

const page = usePage();

const dismissedNow = ref(false);

const visible = computed(
    () =>
        !dismissedNow.value &&
        (page.props.auth as { should_see_privacy_notice?: boolean } | undefined)
            ?.should_see_privacy_notice === true,
);

function dismiss(): void {
    dismissedNow.value = true;
    router.post('/avisos/privacidade', {}, { preserveScroll: true });
}
</script>

<template>
    <div
        v-if="visible"
        class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-b border-border bg-muted/60 px-4 py-2 text-center text-sm"
        role="status"
    >
        <span>
            Atualizámos a Política de Privacidade para incluir os novos canais
            de contacto e suporte e explicar os respetivos prazos de
            conservação.
        </span>
        <a href="/privacidade" class="font-medium underline">Ver a Política</a>
        <button
            type="button"
            class="rounded px-2 py-0.5 text-xs text-muted-foreground hover:bg-foreground/10"
            @click="dismiss"
        >
            Fechar
        </button>
    </div>
</template>
