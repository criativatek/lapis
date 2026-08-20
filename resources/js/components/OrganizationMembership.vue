<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
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
import type { Auth } from '@/types/auth';

const page = usePage();
const auth = computed(() => page.props.auth as Auth);
const organization = computed(() => auth.value.organization);

const isInstitutional = computed(() => organization.value?.type === 'institutional');

const form = useForm({});

function leave(): void {
    form.post('/organizations/leave', { preserveScroll: true });
}
</script>

<template>
    <div v-if="isInstitutional && organization" class="space-y-6">
        <Heading variant="small" title="Organização" description="A sua ligação a esta organização." />

        <div v-if="organization.is_owner" class="rounded-lg border border-border bg-muted/30 p-4 text-sm text-muted-foreground">
            É o responsável por <strong>{{ organization.name }}</strong
            >. Antes de sair, transfira a responsabilidade da organização para outro membro, na página Equipa.
        </div>

        <div v-else class="space-y-4 rounded-lg border border-border p-4">
            <p class="text-sm text-muted-foreground">
                É membro de <strong>{{ organization.name }}</strong
                >.
            </p>
            <Dialog>
                <DialogTrigger as-child>
                    <Button variant="destructive">Sair da organização</Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader class="space-y-3">
                        <DialogTitle>Sair de {{ organization.name }}?</DialogTitle>
                        <DialogDescription>
                            Ao sair, deixa de ter acesso à organização. Os dados pedagógicos já registados
                            permanecem na organização.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button variant="secondary">Cancelar</Button>
                        </DialogClose>
                        <Button variant="destructive" :disabled="form.processing" @click="leave">Sair da organização</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
