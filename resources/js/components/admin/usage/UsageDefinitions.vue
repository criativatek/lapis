<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import type { UsageDefinition } from '@/types/usage';

defineProps<{ definitions: UsageDefinition[] }>();
</script>

<template>
    <Collapsible class="rounded-xl border border-border bg-card">
        <CollapsibleTrigger class="group flex min-h-11 w-full items-center justify-between gap-2 px-4 text-left text-sm font-medium">
            Como ler estes números
            <ChevronDown class="size-4 shrink-0 transition-transform group-data-[state=open]:rotate-180" aria-hidden="true" />
        </CollapsibleTrigger>
        <CollapsibleContent>
            <dl class="space-y-3 border-t border-border px-4 py-3 text-sm">
                <div v-for="definition in definitions" :key="definition.term">
                    <dt class="font-medium">{{ definition.term }}</dt>
                    <dd class="text-muted-foreground">{{ definition.text }}</dd>
                </div>
            </dl>
        </CollapsibleContent>
    </Collapsible>
</template>
