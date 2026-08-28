<script setup lang="ts">
import { Info } from '@lucide/vue';

/**
 * The one sentence that goes under every AI answer in this application.
 *
 * ONE COMPONENT SO THERE IS ONE SENTENCE. Two screens wording this
 * differently is how a disclosure quietly becomes decoration — and the
 * wording is load-bearing: it says the assistant can be wrong, and it says
 * who decides. Both halves are the point (§8).
 *
 * IT IS NOT A WARNING BANNER. Muted, small, and below the answer rather than
 * above it: a notice a teacher has to read past before every reading is a
 * notice they stop seeing after the second one.
 */
withDefaults(defineProps<{ pseudonymised?: boolean }>(), { pseudonymised: false });
</script>

<template>
    <p class="mt-3 flex items-start gap-1.5 text-xs text-muted-foreground">
        <Info aria-hidden="true" class="mt-0.5 size-3.5 shrink-0" />
        <span>
            A IA apoia a análise e pode cometer erros. As decisões pedagógicas continuam a ser do professor.
            <!-- «Pseudonimizados», never «anónimos». The rows carry no name,
                 but the teacher holding the class list can still tell who is
                 who — claiming anonymity would be claiming something this
                 feature does not do. -->
            <template v-if="pseudonymised">
                Os dados enviados são pseudonimizados: não incluem nomes, números nem outros identificadores de alunos.
            </template>
        </span>
    </p>
</template>
