<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Domain = { id: number; label: string };
type ItemDomains = { points_possible: number; domains: { domain_id: number; points: number }[] };

const props = defineProps<{
    item: ItemDomains;
    domains: Domain[];
    selectedDomainIds: number[];
}>();

// A domain that was unselected after a question was already allocated to it
// stays choosable for THAT row (falling back to the full list) so the select
// never silently drops to a blank/mismatched value.
function optionsFor(allocation: { domain_id: number }): Domain[] {
    return props.domains.filter(
        (domain) => props.selectedDomainIds.includes(domain.id) || domain.id === allocation.domain_id,
    );
}

function addAllocation(): void {
    const firstSelectable = props.domains.find((domain) => props.selectedDomainIds.includes(domain.id));
    props.item.domains.push({ domain_id: firstSelectable?.id ?? props.domains[0]?.id ?? 0, points: 0 });
}

function removeAllocation(index: number): void {
    props.item.domains.splice(index, 1);
}

// The question's total is never typed directly — it's the sum of what's
// entered per domain below, kept in sync here as those points change.
watch(
    () => props.item.domains,
    () => {
        if (props.item.domains.length > 0) {
            props.item.points_possible = props.item.domains.reduce(
                (sum, allocation) => sum + (Number(allocation.points) || 0),
                0,
            );
        }
    },
    { deep: true },
);
</script>

<template>
    <div v-if="domains.length" class="space-y-1.5 border-t border-border pt-3">
        <div class="flex items-center justify-between">
            <span class="text-xs text-muted-foreground">Cotação por domínio</span>
            <Button type="button" variant="ghost" size="sm" @click="addAllocation">
                <Plus class="size-3.5" /> Domínio
            </Button>
        </div>
        <div v-for="(allocation, allocationIndex) in item.domains" :key="allocationIndex" class="flex items-center gap-2">
            <select
                v-model.number="allocation.domain_id"
                class="h-7 flex-1 rounded-md border border-input bg-transparent px-2 text-xs text-muted-foreground"
            >
                <option v-for="domain in optionsFor(allocation)" :key="domain.id" :value="domain.id">
                    {{ domain.label }}
                </option>
            </select>
            <Input v-model.number="allocation.points" type="number" min="0" step="0.25" class="h-7 w-16 text-xs" />
            <span class="text-xs text-muted-foreground">pts</span>
            <Button type="button" variant="ghost" size="icon" class="size-7" @click="removeAllocation(allocationIndex)">
                <Trash2 class="size-3.5" />
            </Button>
        </div>
        <p v-if="item.domains.length === 0" class="text-xs text-muted-foreground">
            Sem domínio associado a esta questão ainda — adiciona um acima.
        </p>
    </div>
    <p v-else class="text-xs text-muted-foreground">
        A turma não tem perfil ativo, por isso não há domínios para distribuir.
    </p>
</template>
