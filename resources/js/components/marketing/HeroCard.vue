<script setup lang="ts">
/**
 * The product card that floats over the hero photograph: a slice of the
 * results grid, because the grid IS the product.
 *
 * Demo scenario numbers (DemoDataSeeder — one class, fictional students,
 * pseudonyms as the application shows them). It is not a claim about anybody
 * real, and it never says how many teachers or schools use the product: the
 * site invents no social proof.
 */
const rows = [
    { student: 'A03', domains: ['4,2', '3,8', '—'], proposal: 4, level: 4 },
    { student: 'A07', domains: ['3,1', '3,4', '2,9'], proposal: 3, level: 3 },
    { student: 'A12', domains: ['4,8', '4,5', '4,6'], proposal: 5, level: null },
] as const;
</script>

<template>
    <div
        aria-hidden="true"
        class="absolute right-4 bottom-4 left-4 rounded-2xl bg-white/95 p-4 shadow-[0_24px_60px_-20px_rgba(15,23,42,0.5)] ring-1 ring-black/5 backdrop-blur sm:right-8 sm:bottom-8 sm:left-auto sm:w-[22rem] sm:p-5"
    >
        <div class="flex items-baseline justify-between">
            <p class="text-sm font-semibold text-slate-900">7.º A · Português</p>
            <p class="text-[11px] text-slate-500">2.º período</p>
        </div>
        <table class="mt-3 w-full text-[12px] text-slate-700">
            <thead>
                <tr
                    class="text-[10px] font-semibold tracking-[0.1em] text-slate-500 uppercase"
                >
                    <th class="pb-1.5 text-left font-semibold">Aluno</th>
                    <th class="pb-1.5 text-right font-semibold">Oral</th>
                    <th class="pb-1.5 text-right font-semibold">Escr.</th>
                    <th class="pb-1.5 text-right font-semibold">Leit.</th>
                    <th class="pb-1.5 text-right font-semibold">Prop.</th>
                    <th class="pb-1.5 text-right font-semibold">Nível</th>
                </tr>
            </thead>
            <tbody class="tabular-nums">
                <tr
                    v-for="row in rows"
                    :key="row.student"
                    class="border-t border-slate-100"
                >
                    <td class="py-1.5 font-medium">{{ row.student }}</td>
                    <td
                        v-for="(value, index) in row.domains"
                        :key="index"
                        class="py-1.5 text-right"
                        :class="value === '—' ? 'text-slate-400' : undefined"
                    >
                        {{ value }}
                    </td>
                    <td class="py-1.5 text-right text-slate-500">
                        {{ row.proposal }}
                    </td>
                    <td class="py-1.5 text-right">
                        <span
                            v-if="row.level !== null"
                            class="inline-block min-w-6 rounded-md bg-blue-600 px-1.5 py-0.5 text-center text-[11px] font-semibold text-white"
                            >{{ row.level }}</span
                        >
                        <span
                            v-else
                            class="inline-block rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800"
                            >confirmar</span
                        >
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="mt-3 text-[11px] leading-snug text-slate-500">
            «—» é não aplicável: sai do cálculo. A proposta é do Lapispro, o
            nível é do professor.
        </p>
    </div>
</template>
