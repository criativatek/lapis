<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * The product card over the hero photograph: a slice of the results grid,
 * because the grid IS the product.
 *
 * Demo scenario numbers (DemoDataSeeder — one class, fictional students,
 * pseudonyms as the application shows them). It is not a claim about anybody
 * real, and it never says how many teachers or schools use the product: the
 * site invents no social proof.
 *
 * THE ONE MOVING PART ON THE PAGE. When the card scrolls into view, the last
 * row's «confirmar» becomes the teacher's «5» — the proposal turning into a
 * decision, which is the whole product in 600ms. Once, and never under
 * prefers-reduced-motion (the decided state is shown straight away).
 *
 * Below `lg` the card sits under the photograph instead of over it: on a
 * phone it covered the hands, which are the point of the picture.
 */
const rows = [
    { student: 'A03', domains: ['4,2', '3,8', '—'], proposal: 4, level: 4 },
    { student: 'A07', domains: ['3,1', '3,4', '2,9'], proposal: 3, level: 3 },
    { student: 'A12', domains: ['4,8', '4,5', '4,6'], proposal: 5, level: 5 },
] as const;

const decided = ref(false);
const root = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;
let timer: ReturnType<typeof setTimeout> | null = null;

onMounted(() => {
    const reduced = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    if (reduced || !('IntersectionObserver' in window) || !root.value) {
        decided.value = true;

        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                timer = setTimeout(() => (decided.value = true), 900);
                observer?.disconnect();
            }
        },
        { threshold: 0.6 },
    );
    observer.observe(root.value);
});

onBeforeUnmount(() => {
    observer?.disconnect();

    if (timer) {
        clearTimeout(timer);
    }
});
</script>

<template>
    <div
        ref="root"
        aria-hidden="true"
        class="relative mx-4 -mt-10 mb-4 rounded-2xl bg-white/95 p-4 shadow-[0_24px_60px_-20px_rgba(15,23,42,0.5)] ring-1 ring-black/5 backdrop-blur sm:mx-6 sm:p-5 lg:absolute lg:right-8 lg:bottom-8 lg:mx-0 lg:mt-0 lg:mb-0 lg:w-[22rem]"
    >
        <div class="flex items-baseline justify-between">
            <p class="text-sm font-semibold text-slate-900">
                7.º A · Português
            </p>
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
                    v-for="(row, rowIndex) in rows"
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
                            v-if="rowIndex < rows.length - 1 || decided"
                            class="inline-block min-w-6 rounded-md bg-blue-600 px-1.5 py-0.5 text-center text-[11px] font-semibold text-white"
                            :class="
                                rowIndex === rows.length - 1
                                    ? 'motion-safe:animate-[pop_500ms_cubic-bezier(0.34,1.56,0.64,1)]'
                                    : undefined
                            "
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
