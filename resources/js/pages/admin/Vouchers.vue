<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

/**
 * Admin > Comercial > Vouchers.
 *
 * EMITIR E DESACTIVAR, NADA MAIS. Um voucher é imutável depois de nascer —
 * errou-se, desactiva-se e emite-se outro — por isso não há edição nenhuma
 * nesta página, de propósito. As utilizações mostradas são derivadas das
 * linhas de resgate (confirmadas + reservas vivas), nunca de um contador.
 *
 * O FORMULÁRIO É TIPADO POR FAMÍLIA: escolher o tipo mostra exactamente os
 * campos desse tipo. Um `fixed_price` sem quantia, um `percent_discount` sem
 * percentagem ou um `free_until` sem data são recusados pelo servidor — e
 * pelas CHECK constraints por baixo dele.
 */

type VoucherRow = {
    id: number;
    code: string;
    label: string;
    benefitType: string;
    benefitLabel: string;
    benefitSummary: string;
    planKey: string | null;
    planName: string | null;
    validFrom: string | null;
    validUntil: string | null;
    maxRedemptions: number | null;
    confirmedCount: number;
    reservedCount: number;
    disabledAt: string | null;
    createdAt: string | null;
};

const props = defineProps<{
    vouchers: VoucherRow[];
    benefitTypes: { value: string; label: string }[];
    plans: { id: number; key: string; name: string }[];
}>();

const form = useForm({
    label: '',
    benefit_type: 'fixed_price',
    benefit_amount_cents: null as number | null,
    benefit_percent: null as number | null,
    benefit_free_until: '',
    plan_id: null as number | null,
    valid_from: '',
    valid_until: '',
    max_redemptions: null as number | null,
    code: '',
});

function submit(): void {
    form.post('/admin/commercial/vouchers', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

const disableForm = useForm({});

function disable(voucher: VoucherRow): void {
    if (
        !window.confirm(
            `Desativar o voucher ${voucher.code}? Deixa de poder ser resgatado; os resgates já feitos não são tocados.`,
        )
    ) {
        return;
    }

    disableForm.post(`/admin/commercial/vouchers/${voucher.id}/disable`, {
        preserveScroll: true,
    });
}

const active = computed(() => props.vouchers.filter((v) => !v.disabledAt));
const disabled = computed(() => props.vouchers.filter((v) => v.disabledAt));

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('pt-PT') : '—';
}

function usage(voucher: VoucherRow): string {
    const live = voucher.confirmedCount + voucher.reservedCount;
    const cap = voucher.maxRedemptions === null ? '∞' : voucher.maxRedemptions;

    return `${live} / ${cap}`;
}
</script>

<template>
    <Head title="Vouchers" />

    <div class="space-y-8">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Vouchers</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Códigos comerciais: preço fixo, desconto percentual ou
                    gratuitidade até uma data. Nenhum mexe em módulos nem em
                    versões de plano — só no preço e no termo do contrato.
                </p>
            </div>
            <Button as-child variant="outline" size="sm">
                <Link href="/admin/commercial">← Comercial</Link>
            </Button>
        </div>

        <!-- Emitir -->
        <section class="rounded-lg border border-border p-4">
            <h2 class="text-sm font-medium">Emitir voucher</h2>
            <p class="mt-1 text-xs text-muted-foreground">
                Um voucher emitido é imutável: se estiver errado, desativa-se e
                emite-se outro. Deixar o código em branco gera um.
            </p>

            <form class="mt-4 space-y-4" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="space-y-1 text-sm lg:col-span-2">
                        <span class="text-xs text-muted-foreground"
                            >Etiqueta interna</span
                        >
                        <input
                            v-model="form.label"
                            type="text"
                            maxlength="120"
                            placeholder="Ex.: Campanha formação outubro 2026"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        />
                        <InputError :message="form.errors.label" />
                    </label>

                    <label class="space-y-1 text-sm">
                        <span class="text-xs text-muted-foreground"
                            >Tipo de benefício</span
                        >
                        <select
                            v-model="form.benefit_type"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        >
                            <option
                                v-for="option in benefitTypes"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.benefit_type" />
                    </label>

                    <label
                        v-if="form.benefit_type === 'fixed_price'"
                        class="space-y-1 text-sm"
                    >
                        <span class="text-xs text-muted-foreground"
                            >Preço final (cêntimos)</span
                        >
                        <input
                            v-model.number="form.benefit_amount_cents"
                            type="number"
                            min="0"
                            step="1"
                            placeholder="Ex.: 1990 = 19,90 €"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        />
                        <InputError
                            :message="form.errors.benefit_amount_cents"
                        />
                    </label>

                    <label
                        v-if="form.benefit_type === 'percent_discount'"
                        class="space-y-1 text-sm"
                    >
                        <span class="text-xs text-muted-foreground"
                            >Desconto (%)</span
                        >
                        <input
                            v-model.number="form.benefit_percent"
                            type="number"
                            min="1"
                            max="100"
                            step="1"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        />
                        <InputError :message="form.errors.benefit_percent" />
                    </label>

                    <label
                        v-if="form.benefit_type === 'free_until'"
                        class="space-y-1 text-sm"
                    >
                        <span class="text-xs text-muted-foreground"
                            >Gratuito até</span
                        >
                        <input
                            v-model="form.benefit_free_until"
                            type="date"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        />
                        <InputError :message="form.errors.benefit_free_until" />
                    </label>

                    <label class="space-y-1 text-sm">
                        <span class="text-xs text-muted-foreground"
                            >Restrito ao plano</span
                        >
                        <select
                            v-model="form.plan_id"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        >
                            <option :value="null">Qualquer plano</option>
                            <option
                                v-for="plan in plans"
                                :key="plan.id"
                                :value="plan.id"
                            >
                                {{ plan.name }}
                            </option>
                        </select>
                        <InputError :message="form.errors.plan_id" />
                    </label>

                    <label class="space-y-1 text-sm">
                        <span class="text-xs text-muted-foreground"
                            >Válido de (opcional)</span
                        >
                        <input
                            v-model="form.valid_from"
                            type="date"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        />
                        <InputError :message="form.errors.valid_from" />
                    </label>

                    <label class="space-y-1 text-sm">
                        <span class="text-xs text-muted-foreground"
                            >Válido até (opcional)</span
                        >
                        <input
                            v-model="form.valid_until"
                            type="date"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        />
                        <InputError :message="form.errors.valid_until" />
                    </label>

                    <label class="space-y-1 text-sm">
                        <span class="text-xs text-muted-foreground"
                            >Limite de utilizações (vazio = ilimitado)</span
                        >
                        <input
                            v-model.number="form.max_redemptions"
                            type="number"
                            min="1"
                            step="1"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        />
                        <InputError :message="form.errors.max_redemptions" />
                    </label>

                    <label class="space-y-1 text-sm">
                        <span class="text-xs text-muted-foreground"
                            >Código (vazio = gerar)</span
                        >
                        <input
                            v-model="form.code"
                            type="text"
                            maxlength="64"
                            autocapitalize="characters"
                            spellcheck="false"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm font-mono"
                        />
                        <InputError :message="form.errors.code" />
                    </label>
                </div>

                <Button type="submit" :disabled="form.processing">
                    Emitir voucher
                </Button>
            </form>
        </section>

        <!-- Lista -->
        <section class="space-y-3">
            <h2 class="text-sm font-medium">
                Ativos
                <span class="text-muted-foreground">({{ active.length }})</span>
            </h2>
            <p v-if="active.length === 0" class="text-sm text-muted-foreground">
                Nenhum voucher ativo. Nenhum código foi emitido ainda, ou foram
                todos desativados.
            </p>
            <div v-else class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b border-border text-left text-xs text-muted-foreground"
                        >
                            <th class="px-3 py-2 font-medium">Código</th>
                            <th class="px-3 py-2 font-medium">Etiqueta</th>
                            <th class="px-3 py-2 font-medium">Benefício</th>
                            <th class="px-3 py-2 font-medium">Plano</th>
                            <th class="px-3 py-2 font-medium">Janela</th>
                            <th class="px-3 py-2 font-medium">Utilizações</th>
                            <th class="px-3 py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="voucher in active"
                            :key="voucher.id"
                            class="border-b border-border/60 last:border-0"
                        >
                            <td class="px-3 py-2 font-mono text-xs">
                                {{ voucher.code }}
                            </td>
                            <td class="px-3 py-2">{{ voucher.label }}</td>
                            <td class="px-3 py-2">
                                {{ voucher.benefitLabel }}
                                <span class="block text-xs text-muted-foreground">{{
                                    voucher.benefitSummary
                                }}</span>
                            </td>
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ voucher.planName ?? 'Qualquer' }}
                            </td>
                            <td class="px-3 py-2 text-xs text-muted-foreground">
                                {{ formatDate(voucher.validFrom) }} —
                                {{ formatDate(voucher.validUntil) }}
                            </td>
                            <td class="px-3 py-2 tabular-nums">
                                {{ usage(voucher) }}
                                <span
                                    v-if="voucher.reservedCount > 0"
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{ voucher.reservedCount }} em reserva
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="disableForm.processing"
                                    @click="disable(voucher)"
                                >
                                    Desativar
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <template v-if="disabled.length > 0">
                <h2 class="pt-2 text-sm font-medium">
                    Desativados
                    <span class="text-muted-foreground"
                        >({{ disabled.length }})</span
                    >
                </h2>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm">
                        <tbody>
                            <tr
                                v-for="voucher in disabled"
                                :key="voucher.id"
                                class="border-b border-border/60 text-muted-foreground last:border-0"
                            >
                                <td class="px-3 py-2 font-mono text-xs">
                                    {{ voucher.code }}
                                </td>
                                <td class="px-3 py-2">{{ voucher.label }}</td>
                                <td class="px-3 py-2">
                                    {{ voucher.benefitSummary }}
                                </td>
                                <td class="px-3 py-2 tabular-nums">
                                    {{ usage(voucher) }}
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    Desativado em
                                    {{ formatDate(voucher.disabledAt) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>
    </div>
</template>
