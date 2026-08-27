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
 * IT DOES NOT PRETEND TO VALIDATE ANYTHING. There is no voucher backend yet —
 * no table, no endpoint, no redemption. So this form does the one honest thing
 * available: it accepts a code, checks that something was typed, and says
 * plainly that the code is confirmed when the account is created, not here.
 * It NEVER answers «voucher aplicado», and it never answers «código
 * inválido» either — a rejection this page is in no position to issue would
 * turn a working code into a lost customer.
 *
 * THE INTEGRATION POINT IS ONE FUNCTION. When redemption exists, `submit()`
 * is where it goes: post the code, render the server's answer in `status`,
 * delete `PENDING`. Nothing else on the page has to change.
 *
 * WHAT THE VISITOR IS NEVER TOLD is how any of this works — what a code may
 * carry, which campaign it belongs to, whether it is a discount or a period.
 * The benefit is whatever the code carries; explaining the mechanism would
 * invite people to reason about codes they do not have.
 */

const code = ref('');
const status = ref<'idle' | 'empty' | 'pending'>('idle');

const PENDING =
    'Guarde este código. A confirmação é feita ao criar a conta ou já dentro do Lapispro — esta página não valida códigos.';

function submit(): void {
    const trimmed = code.value.trim();

    if (trimmed === '') {
        status.value = 'empty';

        return;
    }

    status.value = 'pending';
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
                            especiais de acesso atribuídas pelo Lapispro. Introduza
                            o seu código para ativar o benefício associado.
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
                                placeholder="Ex.: LAPISPRO-XXXX-XXXX"
                                class="sm:flex-1"
                                :aria-invalid="status === 'empty'"
                                aria-describedby="voucher-status"
                                @input="status = 'idle'"
                            />
                            <Button type="submit" variant="outline">
                                Aplicar voucher
                            </Button>
                        </div>

                        <p
                            id="voucher-status"
                            class="mt-2 min-h-[1.25rem] text-xs leading-relaxed text-pretty"
                            :class="
                                status === 'empty'
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                            "
                            role="status"
                            aria-live="polite"
                        >
                            <span v-if="status === 'empty'"
                                >Introduza o código do voucher.</span
                            >
                            <span v-else-if="status === 'pending'">{{
                                PENDING
                            }}</span>
                        </p>
                    </form>
                </div>
            </RevealOnScroll>
        </div>
    </section>
</template>
