<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Domain = { id: number; label: string };
type ItemDomains = { domains: { domain_id: number; allocation_percent: number }[] };

const props = defineProps<{
    item: ItemDomains;
    domains: Domain[];
}>();

function addAllocation(): void {
    props.item.domains.push({ domain_id: props.domains[0]?.id ?? 0, allocation_percent: 100 });
}

function removeAllocation(index: number): void {
    props.item.domains.splice(index, 1);
}

function allocationTotal(): number {
    return props.item.domains.reduce((sum, allocation) => sum + (Number(allocation.allocation_percent) || 0), 0);
}
</script>

<template>
    <div v-if="domains.length" class="space-y-2 border-t border-border pt-3">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-muted-foreground">
                Domínios <template v-if="item.domains.length">— total {{ allocationTotal() }}%</template>
            </span>
            <Button type="button" variant="ghost" size="sm" @click="addAllocation">
                <Plus class="size-3.5" /> Domínio
            </Button>
        </div>
        <div v-for="(allocation, allocationIndex) in item.domains" :key="allocationIndex" class="flex items-center gap-2">
            <select v-model.number="allocation.domain_id" class="h-8 flex-1 rounded-md border border-input bg-transparent px-2 text-sm">
                <option v-for="domain in domains" :key="domain.id" :value="domain.id">{{ domain.label }}</option>
            </select>
            <Input v-model.number="allocation.allocation_percent" type="number" min="0" max="100" class="h-8 w-20" />
            <span class="text-sm text-muted-foreground">%</span>
            <Button type="button" variant="ghost" size="icon" @click="removeAllocation(allocationIndex)">
                <Trash2 class="size-3.5" />
            </Button>
        </div>
        <p v-if="item.domains.length && Math.abs(allocationTotal() - 100) > 0.0001" class="text-xs text-amber-700">
            Tem de somar 100%.
        </p>
    </div>
    <p v-else class="text-xs text-muted-foreground">
        A turma não tem perfil ativo, por isso não há domínios para distribuir.
    </p>
</template>
