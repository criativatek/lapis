<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Period = {
    // Present for a period loaded from an existing year — its identity across
    // saves. Absent for a period added client-side via addPeriod(): the
    // backend then knows this is a brand-new period, never one being renamed.
    ulid?: string;
    label: string;
    kind: string;
    sequence: number;
    starts_on: string;
    ends_on: string;
};

type YearData = {
    label: string;
    starts_on: string;
    ends_on: string;
    status: string;
    country_code: string;
    region_code: string | null;
    periods: Period[];
};

const props = defineProps<{
    statuses: Option[];
    periodKinds: Option[];
    initial?: YearData;
    submitUrl: string;
    method: 'post' | 'put';
}>();

const form = useForm<YearData>(
    props.initial ?? {
        label: '',
        starts_on: '',
        ends_on: '',
        status: 'draft',
        country_code: 'PT',
        region_code: null,
        periods: [
            { label: '1.º Semestre', kind: 'semester', sequence: 1, starts_on: '', ends_on: '' },
            { label: '2.º Semestre', kind: 'semester', sequence: 2, starts_on: '', ends_on: '' },
        ],
    },
);

function addPeriod(): void {
    form.periods.push({
        label: '',
        kind: 'semester',
        sequence: form.periods.length + 1,
        starts_on: '',
        ends_on: '',
    });
}

function removePeriod(index: number): void {
    form.periods.splice(index, 1);
}

// Period errors arrive under dot-keys (periods.0.starts_on) that the typed
// error map does not know about, so read them through a string index.
function periodError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string>)[`periods.${index}.${field}`];
}

function submit(): void {
    form.submit(props.method, props.submitUrl, { preserveScroll: true });
}
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="label">Designação</Label>
                    <Input id="label" v-model="form.label" placeholder="Ex.: 2026/2027" />
                    <InputError :message="form.errors.label" />
                </div>
                <div class="grid gap-2">
                    <Label for="status">Estado</Label>
                    <select
                        id="status"
                        v-model="form.status"
                        class="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                    >
                        <option v-for="status in statuses" :key="status.value" :value="status.value">
                            {{ status.label }}
                        </option>
                    </select>
                    <InputError :message="form.errors.status" />
                </div>
                <div class="grid gap-2">
                    <Label for="starts_on">Início</Label>
                    <Input id="starts_on" v-model="form.starts_on" type="date" />
                    <InputError :message="form.errors.starts_on" />
                </div>
                <div class="grid gap-2">
                    <Label for="ends_on">Fim</Label>
                    <Input id="ends_on" v-model="form.ends_on" type="date" />
                    <InputError :message="form.errors.ends_on" />
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-semibold">Períodos de avaliação</h2>
                    <p class="text-sm text-muted-foreground">
                        Semestres, trimestres ou outra divisão. Cada período tem de estar dentro do ano.
                    </p>
                </div>
                <Button type="button" variant="outline" size="sm" @click="addPeriod">
                    <Plus class="size-4" /> Adicionar período
                </Button>
            </div>
            <InputError :message="form.errors.periods" />

            <div
                v-for="(period, index) in form.periods"
                :key="index"
                class="grid items-end gap-3 rounded-lg border border-border p-4 sm:grid-cols-[1fr_1fr_5rem_1fr_1fr_auto]"
            >
                <div class="grid gap-1.5">
                    <Label :for="`period-label-${index}`" class="text-xs">Designação</Label>
                    <Input :id="`period-label-${index}`" v-model="period.label" placeholder="Ex.: 1.º Semestre" />
                </div>
                <div class="grid gap-1.5">
                    <Label :for="`period-kind-${index}`" class="text-xs">Tipo</Label>
                    <select
                        :id="`period-kind-${index}`"
                        v-model="period.kind"
                        class="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                    >
                        <option v-for="kind in periodKinds" :key="kind.value" :value="kind.value">
                            {{ kind.label }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label :for="`period-seq-${index}`" class="text-xs">Ordem</Label>
                    <Input :id="`period-seq-${index}`" v-model.number="period.sequence" type="number" min="1" />
                </div>
                <div class="grid gap-1.5">
                    <Label :for="`period-start-${index}`" class="text-xs">Início</Label>
                    <Input :id="`period-start-${index}`" v-model="period.starts_on" type="date" />
                </div>
                <div class="grid gap-1.5">
                    <Label :for="`period-end-${index}`" class="text-xs">Fim</Label>
                    <Input :id="`period-end-${index}`" v-model="period.ends_on" type="date" />
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    :disabled="form.periods.length <= 1"
                    aria-label="Remover período"
                    @click="removePeriod(index)"
                >
                    <Trash2 class="size-4" />
                </Button>
                <InputError class="sm:col-span-6" :message="periodError(index, 'starts_on')" />
            </div>
        </section>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing">Guardar ano letivo</Button>
        </div>
    </form>
</template>
