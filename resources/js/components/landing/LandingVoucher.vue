<script setup lang="ts">
import { Ticket } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import RevealOnScroll from './RevealOnScroll.vue';

/**
 * «Tem um voucher?» — a quiet block, on purpose.
 *
 * It sits after the comparison and before the questions, at a fraction of the
 * weight of the plan cards and the Fundador band: somebody who has a code is
 * looking for this field, and somebody who does not should be able to read
 * past it without wondering what they are missing.
 *
 * IT VALIDATES FOR REAL NOW. `submit()` posts the code to the server
 * (`POST /voucher/validate`) and renders the server's answer — the voucher
 * engine exists, and this page stopped pretending otherwise. What it still
 * does NOT do is redeem: redemption needs an account, and happens in the
 * checkout (priced codes) or on the plan page (free-until codes). The server
 * says so in its own words, and this page repeats nothing on its own
 * authority.
 *
 * WHAT THE VISITOR IS NEVER TOLD is what a code is worth. The server answers
 * in three public categories — valid, no longer available, not recognised —
 * and never with amounts: the benefit shows itself to the person redeeming,
 * signed in. Explaining more here would invite people to reason about codes
 * they do not have.
 */

const code = ref('');
const status = ref<'idle' | 'empty' | 'checking' | 'answered' | 'failed'>(
    'idle',
);
const answer = ref<{ category: string; message: string } | null>(null);

/** Laravel's XSRF cookie, for a fetch the framework will accept. */
function xsrfToken(): string {
    const raw = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));

    return raw ? decodeURIComponent(raw.split('=').slice(1).join('=')) : '';
}

async function submit(): Promise<void> {
    const trimmed = code.value.trim();

    if (trimmed === '') {
        status.value = 'empty';

        return;
    }

    status.value = 'checking';
    answer.value = null;

    try {
        const response = await fetch('/voucher/validate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ code: trimmed }),
        });

        if (!response.ok) {
            status.value = 'failed';

            return;
        }

        answer.value = (await response.json()) as {
            category: string;
            message: string;
        };
        status.value = 'answered';
    } catch {
        status.value = 'failed';
    }
}
</script>

<template>
    <section
        id="voucher"
        class="scroll-mt-[4.5rem] border-t border-border/60 py-12 sm:py-16"
        aria-labelledby="voucher-title"
    >
        <div class="mx-auto w-full max-w-6xl px-6 sm:px-8">
            <RevealOnScroll>
                <div
                    class="grid gap-6 rounded-2xl border border-border/70 bg-card p-6 sm:p-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,20rem)] lg:items-center lg:gap-10"
                >
                    <div class="min-w-0">
                        <h2
                            id="voucher-title"
                            class="flex items-center gap-2.5 text-lg font-semibold tracking-tight"
                        >
                            <Ticket
                                aria-hidden="true"
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            Tem um voucher?
                        </h2>
                        <p
                            class="mt-2 text-sm leading-relaxed text-pretty text-muted-foreground"
                        >
                            Alguns professores poderão beneficiar de condições
                            especiais de acesso atribuídas pelo Lapispro.
                            Verifique aqui o seu código; o resgate faz-se
                            depois de entrar na sua conta.
                        </p>
                    </div>

                    <form class="min-w-0" novalidate @submit.prevent="submit()">
                        <label
                            for="voucher-code"
                            class="text-[11px] font-semibold tracking-[0.1em] text-muted-foreground uppercase"
                        >
                            Código
                        </label>
                        <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                            <Input
                                id="voucher-code"
                                v-model="code"
                                name="voucher"
                                autocomplete="off"
                                autocapitalize="characters"
                                spellcheck="false"
                                placeholder="Escreva o código como o recebeu"
                                class="sm:flex-1"
                                :aria-invalid="status === 'empty'"
                                aria-describedby="voucher-status"
                                @input="status = 'idle'"
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                :disabled="status === 'checking'"
                            >
                                Verificar voucher
                            </Button>
                        </div>

                        <p
                            id="voucher-status"
                            class="mt-2 min-h-[1.25rem] text-xs leading-relaxed text-pretty"
                            :class="
                                status === 'empty' ||
                                status === 'failed' ||
                                (status === 'answered' &&
                                    answer?.category !== 'valid')
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                            "
                            role="status"
                            aria-live="polite"
                        >
                            <span v-if="status === 'empty'"
                                >Introduza o código do voucher.</span
                            >
                            <span v-else-if="status === 'checking'"
                                >A verificar…</span
                            >
                            <span v-else-if="status === 'answered'">{{
                                answer?.message
                            }}</span>
                            <span v-else-if="status === 'failed'"
                                >Não foi possível verificar agora. Tente
                                novamente dentro de instantes.</span
                            >
                        </p>
                    </form>
                </div>
            </RevealOnScroll>
        </div>
    </section>
</template>
