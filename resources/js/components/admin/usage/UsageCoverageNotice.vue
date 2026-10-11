<script setup lang="ts">
import { Info } from '@lucide/vue';
import { formatDate, formatTimeOfDate } from '@/lib/usageFormat';
import type { UsageCoverage } from '@/types/usage';

defineProps<{ coverage: UsageCoverage }>();
</script>

<template>
    <aside class="space-y-1 rounded-xl border border-border bg-muted/40 p-4 text-sm" aria-label="Cobertura dos dados">
        <div class="flex items-start gap-2">
            <Info class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <div class="min-w-0 space-y-1">
                <p v-for="note in coverage.notes" :key="note">{{ note }}</p>
                <p class="text-xs text-muted-foreground">
                    <span v-if="coverage.collection_started_at">Recolha desde {{ formatDate(coverage.collection_started_at) }}.</span>
                    <span v-else>A recolha ainda não começou.</span>
                    {{ ' ' }}
                    <span v-if="coverage.aggregated_at">Dados agregados às {{ formatTimeOfDate(coverage.aggregated_at) }}.</span>
                    <span v-else>A agregação ainda não correu.</span>
                </p>
            </div>
        </div>
    </aside>
</template>
