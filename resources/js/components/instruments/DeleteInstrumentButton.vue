<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{ ulid: string; title: string }>();

/**
 * Eliminar só se oferece quando o servidor diz que não há resultados (can_delete); o servidor
 * volta a recusar se entretanto houver. A confirmação diz o que fica de fora da ação.
 */
function destroy(): void {
    if (!window.confirm(`Eliminar "${props.title}"? Este elemento de avaliação ainda não tem resultados registados. Esta ação não pode ser desfeita a partir do Lapispro.`)) {
        return;
    }

    router.delete(`/instruments/${props.ulid}`);
}
</script>

<template>
    <Button type="button" variant="ghost" size="sm" class="text-destructive hover:text-destructive" data-testid="delete-instrument" @click="destroy">
        <Trash2 class="size-4" />
        Eliminar elemento de avaliação
    </Button>
</template>
