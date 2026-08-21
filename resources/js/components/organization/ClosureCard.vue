<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { ClosureStatus } from '@/types/closure';

const props = defineProps<{
    closure: ClosureStatus | null;
    closureRetentionDays: number;
}>();

function requestOrganizationClosure(): void {
    useForm({}).post('/team/closure', { preserveScroll: true });
}

function cancelOrganizationClosure(): void {
    useForm({}).delete('/team/closure', { preserveScroll: true });
}
</script>

<template>
    <section class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
        <template v-if="props.closure">
            <div class="space-y-0.5 text-red-600 dark:text-red-100">
                <h2 class="text-sm font-medium">Organização em processo de encerramento</h2>
                <p class="text-sm">
                    Pedido em {{ new Date(props.closure.requested_at).toLocaleDateString('pt-PT') }}. Prazo até
                    {{ new Date(props.closure.scheduled_deletion_at).toLocaleDateString('pt-PT') }}
                    <template v-if="props.closure.recoverable"> — {{ props.closure.days_remaining }} dia(s) para recuperar.</template>
                    <template v-else> — o prazo de recuperação já terminou.</template>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline" size="sm">
                    <a href="/data-exports">Exportar dados</a>
                </Button>
                <Button v-if="props.closure.recoverable" size="sm" @click="cancelOrganizationClosure">Reativar organização</Button>
            </div>
        </template>
        <template v-else>
            <div class="space-y-0.5 text-red-600 dark:text-red-100">
                <h2 class="text-sm font-medium">Encerramento da organização</h2>
                <p class="text-sm">
                    A organização ficará disponível para recuperação durante {{ props.closureRetentionDays }} dias. Durante esse período,
                    pode ser reativada e os dados permitidos podem ser exportados. Os membros, o responsável e todos os dados permanecem
                    intactos; só deixa de ser possível trabalhar normalmente. Após esse prazo, ficará elegível para eliminação.
                </p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <Button variant="destructive" size="sm">Encerrar organização</Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader class="space-y-3">
                        <DialogTitle>Encerrar a organização?</DialogTitle>
                        <DialogDescription>
                            A organização ficará disponível para recuperação durante {{ props.closureRetentionDays }} dias. Os membros, o
                            responsável e todos os dados permanecem intactos; só deixa de ser possível trabalhar normalmente. Durante esse
                            período, pode reativar a organização e exportar os dados permitidos. Após esse prazo, ficará elegível para
                            eliminação.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button variant="secondary">Cancelar</Button>
                        </DialogClose>
                        <Button variant="destructive" @click="requestOrganizationClosure">Encerrar organização</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </template>
    </section>
</template>
