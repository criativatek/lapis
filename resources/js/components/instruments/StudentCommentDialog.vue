<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';

/**
 * O editor do comentário privado sobre UM aluno num elemento de avaliação,
 * partilhado pela grelha e pelo separador «Comentários». Grava com
 * `PUT …/students/{inscrição}/comment` `{ body, lock_version }`; corpo vazio
 * apaga. Com `only: []` a página não recarrega, por isso a versão nova (que o
 * servidor incrementa) é devolvida ao dono através de `saved`: sem ela, a
 * gravação seguinte seguia com a antiga e era recusada como conflito.
 */
const props = defineProps<{
    url: string;
    studentName: string;
    body: string | null;
    lockVersion: number;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [value: { body: string | null; lock_version: number }] }>();

const draft = ref(props.body ?? '');
const busy = ref(false);
const error = ref<string | null>(null);

watch(open, (value) => {
    if (value) {
        draft.value = props.body ?? '';
        error.value = null;
    }
});

function save(value: string): void {
    if (busy.value) {
        return;
    }

    const trimmed = value.trim();

    busy.value = true;
    error.value = null;

    router.put(props.url, { body: trimmed, lock_version: props.lockVersion }, {
        preserveScroll: true,
        preserveState: true,
        only: [],
        onSuccess: () => {
            const existed = props.body !== null;
            const lockVersion = trimmed === '' ? 0 : props.lockVersion + 1;

            // Apagar o que já não existia não escreve nada: a versão não anda.
            emit('saved', {
                body: trimmed === '' ? null : trimmed,
                lock_version: trimmed === '' && !existed ? props.lockVersion : lockVersion,
            });
            open.value = false;
        },
        onError: (errors) => {
            error.value = Object.values(errors)[0] ?? 'Não foi possível guardar o comentário.';
        },
        onFinish: () => {
            busy.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Comentário sobre {{ studentName }}</DialogTitle>
                <DialogDescription>
                    Só o professor vê este comentário. Não acompanha a grelha quando a copia para outra turma.
                </DialogDescription>
            </DialogHeader>
            <Textarea v-model="draft" rows="5" maxlength="2000" :aria-label="`Comentário sobre ${studentName}`" data-testid="student-comment-input" />
            <InputError :message="error ?? undefined" />
            <DialogFooter class="gap-2 sm:justify-between">
                <Button
                    v-if="body"
                    type="button"
                    variant="outline"
                    :disabled="busy"
                    data-testid="student-comment-delete"
                    @click="save('')"
                >
                    Apagar comentário
                </Button>
                <Button type="button" :disabled="busy" data-testid="student-comment-save" @click="save(draft)">Guardar</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
