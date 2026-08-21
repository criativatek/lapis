<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
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

const page = usePage();
const closure = () => page.props.closure as ClosureStatus | null;
const retentionDays = () => page.props.closureRetentionDays as number;

function requestClosure(): void {
    router.post('/settings/account-closure', {}, { preserveScroll: true });
}

function cancelClosure(): void {
    router.delete('/settings/account-closure', { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-6">
        <Heading variant="small" title="Encerramento da conta" description="Encerrar a sua conta, com possibilidade de recuperação." />

        <div v-if="closure()" class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
            <div class="space-y-0.5 text-red-600 dark:text-red-100">
                <p class="font-medium">Conta em processo de encerramento</p>
                <p class="text-sm">
                    Pedido em {{ new Date(closure()!.requested_at).toLocaleDateString('pt-PT') }}. Prazo até
                    {{ new Date(closure()!.scheduled_deletion_at).toLocaleDateString('pt-PT') }}
                    <template v-if="closure()!.recoverable"> — {{ closure()!.days_remaining }} dia(s) para recuperar.</template>
                    <template v-else> — o prazo de recuperação já terminou.</template>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline" size="sm">
                    <a href="/data-exports">Exportar os meus dados</a>
                </Button>
                <Button v-if="closure()!.recoverable" variant="default" size="sm" @click="cancelClosure">Reativar conta</Button>
            </div>
        </div>

        <div v-else class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
            <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
                <p class="font-medium">Encerrar conta</p>
                <p class="text-sm">
                    A sua conta ficará disponível para recuperação durante {{ retentionDays() }} dias. Durante esse período poderá
                    reativá-la e exportar os seus dados. Após esse prazo, ficará elegível para eliminação.
                </p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <Button variant="destructive" size="sm">Encerrar conta</Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader class="space-y-3">
                        <DialogTitle>Encerrar a sua conta?</DialogTitle>
                        <DialogDescription>
                            A sua conta ficará disponível para recuperação durante {{ retentionDays() }} dias. Durante esse período
                            poderá reativá-la e exportar os seus dados. Após esse prazo, ficará elegível para eliminação.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button variant="secondary">Cancelar</Button>
                        </DialogClose>
                        <Button variant="destructive" @click="requestClosure">Encerrar conta</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
