<script setup lang="ts">
/**
 * «Adicionar medida» — registo manual de uma medida associada, a partir do
 * cartão de Caracterização pedagógica de um aluno.
 *
 * O CATÁLOGO NUNCA É DUPLICADO AQUI. `supportMeasureLevels` vem já resolvido
 * pelo servidor (o mesmo `InterventionLegalFramework::supportMeasureLevels()`
 * que Estratégias e Medidas usa) — este diálogo só o mostra, nunca decide o
 * nível de uma medida por si.
 *
 * Um POST simples, com o código escolhido; o servidor resolve o nível, faz a
 * deduplicação e cria a Intervention estruturada (ver
 * ClassCharacterisationController::storeMeasure()). Em erro, o diálogo fica
 * aberto e mostra a mensagem devolvida.
 */
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import NativeSelect from '@/components/ui/NativeSelect.vue';

type SupportMeasureLevel = {
    value: string;
    label: string;
    measures: { value: string; label: string }[];
};

const props = defineProps<{
    open: boolean;
    classUlid: string;
    enrollmentUlid: string;
    studentName: string;
    supportMeasureLevels: SupportMeasureLevel[];
}>();

const emit = defineEmits<{ 'update:open': [boolean] }>();

const selectedCode = ref('');
const submitting = ref(false);
const error = ref<string | null>(null);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            selectedCode.value = '';
            error.value = null;
        }
    },
);

function close(): void {
    emit('update:open', false);
}

const hasMeasures = computed(() => props.supportMeasureLevels.some((level) => level.measures.length > 0));

function submit(): void {
    if (selectedCode.value === '') {
        return;
    }

    submitting.value = true;
    error.value = null;

    router.post(
        `/classes/${props.classUlid}/students/${props.enrollmentUlid}/characterisation/measures`,
        { support_measure_code: selectedCode.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                close();
            },
            onError: (errors) => {
                error.value = errors.support_measure_code ?? 'Não foi possível associar a medida.';
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Adicionar medida — {{ studentName }}</DialogTitle>
            </DialogHeader>

            <div class="space-y-3">
                <p v-if="!hasMeasures" class="text-sm text-muted-foreground">
                    Não há medidas disponíveis no enquadramento legal aplicável.
                </p>

                <div v-else class="grid gap-1.5">
                    <Label for="add-measure-code">Medida</Label>
                    <NativeSelect id="add-measure-code" v-model="selectedCode">
                        <option value="" disabled>Escolha uma medida…</option>
                        <optgroup v-for="level in supportMeasureLevels" :key="level.value" :label="level.label">
                            <option v-for="measure in level.measures" :key="measure.value" :value="measure.value">
                                {{ measure.label }}
                            </option>
                        </optgroup>
                    </NativeSelect>
                </div>

                <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" :disabled="submitting" @click="close">Cancelar</Button>
                <Button type="button" :disabled="submitting || selectedCode === ''" @click="submit">Associar</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
