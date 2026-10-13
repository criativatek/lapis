<script setup lang="ts">
import { Info } from '@lucide/vue';
import { formatRange } from '@/lib/usageFormat';
import type { UsageSignal } from '@/types/usage';

/**
 * Um sinal de acompanhamento: sugestão de verificação, nunca um diagnóstico.
 * A razão e a janela ficam à vista — no telemóvel não há hover — e o tom é
 * neutro (nem vermelho, nem o âmbar de «cuidado/feito» da marca).
 */
defineProps<{ signal: UsageSignal }>();
</script>

<template>
    <div class="rounded-md border border-border bg-muted/50 px-2.5 py-1.5 text-xs">
        <p class="flex items-center gap-1.5 font-medium text-foreground">
            <Info class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
            {{ signal.label }}
        </p>
        <p class="mt-0.5 text-muted-foreground">{{ signal.reason }}</p>
        <p class="mt-0.5 text-muted-foreground">Janela: {{ formatRange(signal.window_from, signal.window_to) }}</p>
    </div>
</template>
