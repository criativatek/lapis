<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = {
    id: number;
    label: string;
    system?: boolean;
    kind: string;
    min_value: number;
    max_value: number;
};

type DomainRow = { name: string; weight: number };

type ProfileData = {
    name: string;
    academic_year_id: number | null;
    subject_id: number | null;
    grade_level: string;
    description: string | null;
    scale_id: number | null;
    domains: DomainRow[];
};

const props = defineProps<{
    academicYears: Option[];
    subjects: Option[];
    scales: Option[];
    initial?: ProfileData;
    submitUrl: string;
    method: 'post' | 'put';
}>();

const form = useForm<ProfileData>(
    props.initial ?? {
        name: '',
        academic_year_id: null,
        subject_id: null,
        grade_level: '',
        description: null,
        scale_id: null,
        domains: [
            { name: '', weight: 0 },
            { name: '', weight: 0 },
        ],
    },
);

const totalWeight = computed(() =>
    form.domains.reduce((sum, domain) => sum + (Number(domain.weight) || 0), 0),
);

const weightsOk = computed(() => Math.abs(totalWeight.value - 100) < 0.0001);
const scaleDialogOpen = ref(false);
const scaleForm = useForm<{
    name: string;
    min_value: number | null;
    max_value: number | null;
}>({
    name: '',
    min_value: null,
    max_value: null,
});

function openScaleDialog(): void {
    scaleForm.reset();
    scaleForm.clearErrors();
    scaleDialogOpen.value = true;
}

function submitScale(): void {
    scaleForm.post('/scales', {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            const newScale = props.scales.find(
                (scale) => !scale.system && scale.label === scaleForm.name,
            );

            if (newScale) {
                form.scale_id = newScale.id;
            }

            scaleDialogOpen.value = false;
            scaleForm.reset();
        },
    });
}

function addDomain(): void {
    form.domains.push({ name: '', weight: 0 });
}

function removeDomain(index: number): void {
    form.domains.splice(index, 1);
}

function domainError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string>)[`domains.${index}.${field}`];
}

function scaleRangeLabel(scale: Option): string {
    return `${scale.label} (${Number(scale.min_value)} a ${Number(scale.max_value)})`;
}

function submit(): void {
    form.submit(props.method, props.submitUrl, { preserveScroll: true });
}
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2 sm:col-span-2">
                <Label for="name">Nome do perfil</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    placeholder="Português – 7.º Ano – Escala 1 a 5"
                />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="academic_year_id">Ano letivo</Label>
                <select
                    id="academic_year_id"
                    v-model.number="form.academic_year_id"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option :value="null" disabled>Escolher…</option>
                    <option
                        v-for="year in academicYears"
                        :key="year.id"
                        :value="year.id"
                    >
                        {{ year.label }}
                    </option>
                </select>
                <InputError :message="form.errors.academic_year_id" />
            </div>
            <div class="grid gap-2">
                <Label for="subject_id">Disciplina</Label>
                <select
                    id="subject_id"
                    v-model.number="form.subject_id"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option :value="null" disabled>Escolher…</option>
                    <option
                        v-for="subject in subjects"
                        :key="subject.id"
                        :value="subject.id"
                    >
                        {{ subject.label }}
                    </option>
                </select>
                <InputError :message="form.errors.subject_id" />
            </div>
            <div class="grid gap-2">
                <Label for="grade_level">Ano de escolaridade</Label>
                <Input
                    id="grade_level"
                    v-model="form.grade_level"
                    placeholder="7.º"
                />
                <InputError :message="form.errors.grade_level" />
            </div>
            <div class="grid gap-2">
                <div class="flex items-center justify-between gap-3">
                    <Label for="scale_id">Escala</Label>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="openScaleDialog"
                    >
                        <Plus class="size-4" /> Criar escala personalizada
                    </Button>
                </div>
                <select
                    id="scale_id"
                    v-model.number="form.scale_id"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option :value="null" disabled>Escolher…</option>
                    <optgroup label="Escalas do sistema">
                        <option
                            v-for="scale in scales.filter(
                                (item) => item.system === true,
                            )"
                            :key="scale.id"
                            :value="scale.id"
                        >
                            {{ scaleRangeLabel(scale) }}
                        </option>
                    </optgroup>
                    <optgroup label="Escalas personalizadas">
                        <option
                            v-for="scale in scales.filter(
                                (item) => item.system === false,
                            )"
                            :key="scale.id"
                            :value="scale.id"
                        >
                            {{ scaleRangeLabel(scale) }}
                        </option>
                    </optgroup>
                </select>
                <InputError :message="form.errors.scale_id" />
            </div>
        </section>

        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-semibold">
                        Domínios e ponderações
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        A soma das ponderações tem de ser 100% para ativar o
                        perfil.
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addDomain"
                >
                    <Plus class="size-4" /> Adicionar domínio
                </Button>
            </div>
            <InputError :message="form.errors.domains" />

            <div class="space-y-2">
                <div
                    v-for="(domain, index) in form.domains"
                    :key="index"
                    class="grid items-end gap-3 rounded-lg border border-border p-3 sm:grid-cols-[1fr_8rem_auto]"
                >
                    <div class="grid gap-1.5">
                        <Label :for="`domain-name-${index}`" class="text-xs"
                            >Domínio</Label
                        >
                        <Input
                            :id="`domain-name-${index}`"
                            v-model="domain.name"
                            placeholder="Leitura"
                        />
                        <InputError :message="domainError(index, 'name')" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`domain-weight-${index}`" class="text-xs"
                            >Ponderação (%)</Label
                        >
                        <Input
                            :id="`domain-weight-${index}`"
                            v-model.number="domain.weight"
                            type="number"
                            min="0"
                            max="100"
                            step="0.5"
                        />
                        <InputError :message="domainError(index, 'weight')" />
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :disabled="form.domains.length <= 1"
                        aria-label="Remover domínio"
                        @click="removeDomain(index)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
            </div>

            <div
                class="flex items-center justify-between rounded-lg border px-4 py-2.5 text-sm"
                :class="
                    weightsOk
                        ? 'border-emerald-300 bg-emerald-50 text-emerald-900'
                        : 'border-amber-300 bg-amber-50 text-amber-900'
                "
            >
                <span class="font-medium">Total das ponderações</span>
                <span class="font-semibold tabular-nums"
                    >{{ totalWeight }}%
                    {{ weightsOk ? '✓' : '(tem de ser 100%)' }}</span
                >
            </div>
        </section>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing"
                >Guardar perfil</Button
            >
            <span class="text-sm text-muted-foreground"
                >Guardar cria/atualiza um rascunho. A ativação faz-se na
                lista.</span
            >
        </div>
    </form>

    <Dialog v-model:open="scaleDialogOpen">
        <DialogContent>
            <form @submit.prevent="submitScale">
                <DialogHeader>
                    <DialogTitle>Criar escala personalizada</DialogTitle>
                    <DialogDescription
                        >Defina o nome e a gama numérica da
                        escala.</DialogDescription
                    >
                </DialogHeader>

                <div class="grid gap-4 py-4">
                    <div class="grid gap-2">
                        <Label for="scale-name">Nome</Label>
                        <Input id="scale-name" v-model="scaleForm.name" />
                        <InputError :message="scaleForm.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="scale-min-value">Valor mínimo</Label>
                        <Input
                            id="scale-min-value"
                            :model-value="scaleForm.min_value ?? undefined"
                            type="number"
                            step="any"
                            @update:model-value="
                                scaleForm.min_value =
                                    $event === undefined || $event === ''
                                        ? null
                                        : Number($event)
                            "
                        />
                        <InputError :message="scaleForm.errors.min_value" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="scale-max-value">Valor máximo</Label>
                        <Input
                            id="scale-max-value"
                            :model-value="scaleForm.max_value ?? undefined"
                            type="number"
                            step="any"
                            @update:model-value="
                                scaleForm.max_value =
                                    $event === undefined || $event === ''
                                        ? null
                                        : Number($event)
                            "
                        />
                        <InputError :message="scaleForm.errors.max_value" />
                    </div>
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="scaleForm.processing"
                        >Criar escala</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
