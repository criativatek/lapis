<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Info, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

/**
 * O QUE O HORÁRIO NÃO MUDOU, DITO AO PROFESSOR (0.168.0).
 *
 * Alterar ou terminar um tempo nunca apaga nem muda de turma uma aula com
 * registos: essas aulas ficam onde estavam. O servidor diz quais (e porquê) na
 * flash `scheduleNotice`; aqui fica à vista até o professor a fechar — não é um
 * toast que desaparece, porque explica um resultado que, sem a explicação,
 * parece uma alteração que falhou.
 *
 * A flash do Inertia só viaja na resposta que a trouxe; por isso o aviso é
 * copiado para estado local e sobrevive a navegar de semana (visitas com
 * `preserveState`) até ser fechado ou substituído por outro.
 */
const page = usePage();

const message = ref<string | null>(null);

watch(
    () =>
        (page.flash as { scheduleNotice?: { message?: string } } | undefined)
            ?.scheduleNotice?.message ?? null,
    (incoming) => {
        if (incoming) {
            message.value = incoming;
        }
    },
    { immediate: true },
);
</script>

<template>
    <Alert v-if="message" data-schedule-notice class="relative pr-12">
        <Info class="size-4" />
        <AlertTitle>Alteração ao horário</AlertTitle>
        <AlertDescription>{{ message }}</AlertDescription>
        <Button
            type="button"
            variant="ghost"
            size="icon"
            class="absolute top-2 right-2 size-8"
            aria-label="Fechar o aviso"
            data-dismiss-schedule-notice
            @click="message = null"
            ><X class="size-4"
        /></Button>
    </Alert>
</template>
