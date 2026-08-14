<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Domain = { id: number; label: string };
type ItemDomains = { points_possible: number; domains: { domain_id: number; points: number }[] };

const props = defineProps<{
    item: ItemDomains;
    domains: Domain[];
    selectedDomainIds: number[];
}>();

// Many instruments assess a single domain — a "Ficha de Gramática" is all
// Gramática. There is no other distribution possible there, so asking the
// teacher to pick the same domain and type 100% on every question would be
// twenty pointless edits. When exactly one domain is selected, the allocation
// is stated rather than configured.
//
// Presentation only: keeping the single allocation in step with the question's
// points is the parent's job, since form.items is its own state and mutating a
// prop from here would be the wrong way round. The allocation is persisted
// exactly as before, and the calculation engine sees no difference.
const soleDomain = computed(() =>
    props.selectedDomainIds.length === 1
        ? (props.domains.find((domain) => domain.id === props.selectedDomainIds[0]) ?? null)
        : null,
);

const isSingleDomain = computed(
    () =>
        soleDomain.value !== null &&
        props.item.domains.length <= 1 &&
        (props.item.domains.length === 0 ||
            props.item.domains[0].domain_id === soleDomain.value.id),
);

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
// entered per domain below, kept in sync here as those points change. Skipped
// for a single-domain instrument, where the parent drives the points the other
// way round and this would fight it.
watch(
    () => props.item.domains,
    () => {
        if (isSingleDomain.value) {
            return;
        }

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
    <!--
        Single-domain instrument: nothing to distribute, so the domain is stated
        rather than configured. The teacher still sees which domain the question
        counts toward — they just never have to type 100% again.
    -->
    <div
        v-if="domains.length && isSingleDomain && soleDomain"
        class="border-t border-border pt-2 text-xs text-muted-foreground"
    >
        Domínio: <span class="text-foreground">{{ soleDomain.label }}</span>
    </div>

    <div
        v-else-if="domains.length"
        class="space-y-1.5 border-t border-border pt-3"
    >
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
