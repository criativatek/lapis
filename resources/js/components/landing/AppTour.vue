<script setup lang="ts">
import { ref } from 'vue';
import AppShellMock from './AppShellMock.vue';
import type { TourRegion } from './tourRegions';
import { TOUR_STOPS } from './tourRegions';

/**
 * «O Lapispro por dentro» — the shell, with one explanation per region.
 *
 * TWO WAYS IN, ON PURPOSE. Pointing at a region of the picture lights its
 * explanation; moving through the list lights the region. The list is the
 * accessible path — it is made of real buttons, reachable by Tab, and it is why
 * the picture itself carries no interactivity: a hotspot over the table would
 * have to be a button wrapping a table, which is neither valid nor usable.
 */

const active = ref<TourRegion | null>(null);
</script>

<template>
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
        <AppShellMock :active="active" @enter="active = $event" />

        <div>
            <p class="text-sm text-muted-foreground">
                Passe pelas zonas para ver o que cada uma faz.
            </p>

            <ul class="mt-3 space-y-1.5">
                <li v-for="stop in TOUR_STOPS" :key="stop.key">
                    <button
                        type="button"
                        class="w-full rounded-xl border p-3.5 text-left transition-all duration-300 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="
                            active === stop.key
                                ? 'border-(--brand-amber) bg-amber-50/60 dark:bg-amber-950/20'
                                : 'border-border/70 hover:border-border hover:bg-muted/50'
                        "
                        :aria-pressed="active === stop.key"
                        @mouseenter="active = stop.key"
                        @mouseleave="active = null"
                        @focus="active = stop.key"
                        @blur="active = null"
                    >
                        <span
                            class="block text-sm font-semibold tracking-tight text-balance"
                        >
                            {{ stop.title }}
                        </span>
                        <span
                            class="mt-1 block text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ stop.body }}
                        </span>
                    </button>
                </li>
            </ul>

            <p class="mt-4 text-[11px] leading-relaxed text-muted-foreground">
                Uma reprodução do interface com dados de exemplo. Os módulos que
                cada organização vê dependem do plano.
            </p>
        </div>
    </div>
</template>
