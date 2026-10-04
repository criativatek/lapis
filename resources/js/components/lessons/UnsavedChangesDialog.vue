<script setup lang="ts">
/**
 * «TENS ALTERAÇÕES POR GUARDAR» — o aviso da navegação DENTRO da aplicação.
 *
 * Três saídas, e a segura é a que tem o foco: continuar a editar. «Guardar e
 * continuar» espera pela resposta do servidor com o diálogo bloqueado, e só
 * continua se a gravação foi aceite; se falhar (rede, validação, conflito com
 * outra janela), o diálogo fecha, fica-se onde se estava e o texto continua no
 * editor. Fechar o separador ou recarregar não passa por aqui: aí manda a
 * proteção nativa do browser (`beforeunload`), a única que ele permite.
 */
import { Save } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    open: boolean;
    context: string | null;
    saving: boolean;
}>();
const emit = defineEmits<{ stay: []; leave: []; save: [] }>();

const stayButton = ref<InstanceType<typeof Button> | null>(null);

function onOpenChange(value: boolean): void {
    if (!value && !props.saving) {
        emit('stay');
    }
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            setTimeout(
                () =>
                    (stayButton.value?.$el as HTMLElement | undefined)?.focus(),
                0,
            );
        }
    },
);
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent
            class="sm:max-w-lg"
            :show-close-button="false"
            data-testid="unsaved-changes-dialog"
            @escape-key-down="
                (event: Event) => saving && event.preventDefault()
            "
        >
            <DialogHeader class="space-y-2">
                <DialogTitle>Tens alterações por guardar</DialogTitle>
                <DialogDescription>
                    <template v-if="context"
                        >No sumário de
                        <strong class="text-foreground">{{ context }}</strong
                        >. </template
                    >Se continuares sem guardar, perdes o que escreveste.
                </DialogDescription>
            </DialogHeader>
            <p
                v-if="saving"
                role="status"
                class="flex items-center gap-2 text-sm text-muted-foreground"
            >
                <Spinner /> A guardar o sumário…
            </p>
            <DialogFooter class="gap-2 sm:gap-2">
                <Button
                    ref="stayButton"
                    type="button"
                    variant="outline"
                    class="min-h-11 sm:min-h-9"
                    :disabled="saving"
                    data-testid="unsaved-stay"
                    @click="emit('stay')"
                >
                    Continuar a editar
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 text-destructive sm:min-h-9"
                    :disabled="saving"
                    data-testid="unsaved-leave"
                    @click="emit('leave')"
                >
                    Sair sem guardar
                </Button>
                <Button
                    type="button"
                    class="min-h-11 sm:min-h-9"
                    :disabled="saving"
                    data-testid="unsaved-save"
                    @click="emit('save')"
                >
                    <Spinner v-if="saving" />
                    <Save v-else class="size-4" aria-hidden="true" />
                    {{ saving ? 'A guardar…' : 'Guardar e continuar' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
