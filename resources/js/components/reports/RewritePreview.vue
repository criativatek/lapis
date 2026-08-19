<script setup lang="ts">
import { Check, RefreshCw, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';

export type RewriteSuggestion = {
    section: string;
    section_key: string;
    heading: string;
    mode: string;
    mode_label: string;
    current: string;
    text: string | null;
    provider: string;
    model: string;
    pseudonymised: boolean;
    message: string | null;
};

/**
 * The two texts, side by side, and nothing chosen yet (§19).
 *
 * THE SUGGESTION NEVER REPLACES ANYTHING BY ITSELF. It is shown next to what the
 * section says now, and the teacher takes it, keeps what they have, or asks
 * again. This is the whole difference between a tool that helps somebody write
 * and a tool that writes for them — and in a document about a child, that
 * difference is the feature.
 *
 * «MANTER ATUAL» IS A REAL BUTTON, not a way of closing a panel. Nothing was
 * written when the suggestion arrived and nothing is written when it is
 * dismissed; saying so plainly is what lets a teacher click «Aperfeiçoar» on a
 * paragraph they care about without wondering what they are risking.
 *
 * A REFUSED SUGGESTION SHOWS ITS SENTENCE AND NO TEXT. There is nothing to
 * compare — the answer was discarded before it got here — so the panel says the
 * original was preserved and offers to try again.
 */
defineProps<{
    suggestion: RewriteSuggestion;
    busy: boolean;
}>();

const emit = defineEmits<{ accept: [text: string]; retry: []; dismiss: [] }>();
</script>

<template>
    <div class="mt-3 space-y-3 rounded-lg border border-primary/40 bg-primary/5 p-3">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="text-sm font-medium">Sugestão de redação — {{ suggestion.mode_label }}</p>
            <p class="text-xs text-muted-foreground">
                {{ suggestion.provider }} · {{ suggestion.model }}
                <span v-if="suggestion.pseudonymised"> · nomes substituídos no envio</span>
            </p>
        </div>

        <p v-if="suggestion.message" class="text-sm text-muted-foreground">
            {{ suggestion.message }}
        </p>

        <div v-if="suggestion.text" class="grid gap-3 md:grid-cols-2">
            <div class="space-y-1">
                <p class="text-xs font-medium text-muted-foreground uppercase">Texto atual</p>
                <p class="rounded-md border border-border bg-background p-3 text-sm leading-relaxed whitespace-pre-line">
                    {{ suggestion.current }}
                </p>
            </div>
            <div class="space-y-1">
                <p class="text-xs font-medium text-muted-foreground uppercase">Sugestão</p>
                <p class="rounded-md border border-primary/40 bg-background p-3 text-sm leading-relaxed whitespace-pre-line">
                    {{ suggestion.text }}
                </p>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <Button v-if="suggestion.text" size="sm" :disabled="busy" @click="emit('accept', suggestion.text)">
                <Check class="size-3.5" />
                Usar sugestão
            </Button>
            <Button variant="ghost" size="sm" :disabled="busy" @click="emit('retry')">
                <RefreshCw class="size-3.5" />
                Voltar a tentar
            </Button>
            <Button variant="ghost" size="sm" @click="emit('dismiss')">
                <X class="size-3.5" />
                Manter atual
            </Button>
        </div>

        <p class="text-xs text-muted-foreground">
            Nada foi alterado. A sugestão só é aplicada se carregar em «Usar sugestão».
        </p>
    </div>
</template>
