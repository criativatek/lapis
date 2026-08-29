<script setup lang="ts">
import { ShieldAlert, ShieldCheck } from '@lucide/vue';
import type { PersonalDataFinding } from '@/lib/personalData';

/**
 * The standing notice on a free-text field, and the confirmation that replaces
 * it when something was found.
 *
 * TWO STATES, ONE COMPONENT, AND THEY OCCUPY THE SAME PLACE ON PURPOSE. The
 * quiet notice sits under the field from the moment the page loads — it is the
 * part that actually prevents things, because it is read before anybody types.
 * When a check finds something it turns into the confirmation, in the same
 * spot, so the answer appears where the reader was already looking instead of
 * somewhere they have to hunt for.
 *
 * THE NOTICE IS NEVER HIDDEN BY SUCCESS. There is no «tudo bem» state: a green
 * tick after every check would train people to expect one and to read the
 * warning as an error rather than as the rule.
 *
 * IT NAMES THE KIND, NEVER THE VALUE. «Parece conter um endereço de correio
 * eletrónico», not the address — putting the matched text on screen would be
 * displaying the very thing the guard exists to keep out of a prompt.
 *
 * IT CHANGES NOTHING. No `v-model`, no emit that carries text, no way to reach
 * the field it sits under. The two buttons emit and stop.
 */

withDefaults(
    defineProps<{
        /** Non-empty puts the component into confirmation mode. */
        findings?: PersonalDataFinding[];
        /** The standing sentence. Each screen words it for what it is asking for. */
        notice: string;
        /** What the confirmation says the request is, so «continuar» is unambiguous. */
        actionLabel?: string;
    }>(),
    { findings: () => [], actionLabel: 'Continuar mesmo assim' },
);

const emit = defineEmits<{ (event: 'edit'): void; (event: 'proceed'): void }>();
</script>

<template>
    <div
        v-if="findings.length === 0"
        class="mt-1.5 flex items-start gap-1.5 text-xs text-muted-foreground"
    >
        <ShieldCheck aria-hidden="true" class="mt-0.5 size-3.5 shrink-0" />
        <span>{{ notice }}</span>
    </div>

    <!-- `alert` rather than `status`: this interrupts a submit the teacher just
         asked for, and a screen reader has to say so now rather than when it
         next gets a turn. -->
    <div
        v-else
        role="alert"
        class="mt-2 rounded-lg border border-amber-600/40 bg-amber-500/10 p-3 text-xs text-amber-900 dark:text-amber-300"
    >
        <p class="flex items-start gap-1.5 font-medium">
            <ShieldAlert aria-hidden="true" class="mt-0.5 size-3.5 shrink-0" />
            <span>Este texto pode conter dados pessoais. Reveja-o antes de continuar.</span>
        </p>

        <ul class="mt-2 space-y-0.5 pl-5">
            <li v-for="finding in findings" :key="finding.rule">— Parece conter {{ finding.label }}.</li>
        </ul>

        <p class="mt-2 text-amber-900/80 dark:text-amber-300/80">
            O texto não foi alterado nem enviado.
        </p>

        <div class="mt-3 flex flex-wrap gap-2">
            <button
                type="button"
                class="inline-flex h-8 items-center rounded-md border border-amber-700/40 px-3 font-medium hover:bg-amber-500/10"
                @click="emit('edit')"
            >
                Voltar e editar
            </button>
            <button
                type="button"
                class="inline-flex h-8 items-center rounded-md px-3 font-medium underline underline-offset-2 hover:bg-amber-500/10"
                @click="emit('proceed')"
            >
                {{ actionLabel }}
            </button>
        </div>
    </div>
</template>
