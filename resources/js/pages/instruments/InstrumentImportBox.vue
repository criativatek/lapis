<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Candidate = {
    ulid: string;
    title: string;
    class_label: string;
    applied_on: string;
};

defineProps<{
    instruments: Candidate[];
    note?: string | null;
}>();

const selected = defineModel<string | null>({ default: null });

defineEmits<{ import: [] }>();
</script>

<template>
    <div class="flex flex-wrap items-end gap-3 rounded-lg border border-dashed border-border p-3" data-testid="import-box">
        <div class="grid gap-1.5">
            <Label class="text-xs">Importar de outro elemento de avaliação</Label>
            <select
                v-model="selected"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                data-testid="import-select"
            >
                <option :value="null">Nenhum</option>
                <option v-for="instrument in instruments" :key="instrument.ulid" :value="instrument.ulid">
                    {{ instrument.title }} · {{ instrument.class_label }} · {{ instrument.applied_on }}
                </option>
            </select>
        </div>
        <Button type="button" variant="outline" size="sm" :disabled="!selected" @click="$emit('import')">
            Importar questões
        </Button>
        <p class="w-full text-xs text-muted-foreground">
            Copia o título, a cotação total, as questões e os conteúdos — nunca notas de alunos. Tipo, data e período
            escolhem-se aqui. Continua tudo editável depois de importar.
        </p>
        <p v-if="note" class="w-full text-xs font-medium text-amber-800" role="status" data-testid="import-note">
            {{ note }}
        </p>
    </div>
</template>
