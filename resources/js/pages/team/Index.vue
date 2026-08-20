<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowLeftRight, Mail, UserMinus, X } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Member = {
    id: number;
    name: string;
    email: string;
    is_owner: boolean;
    active: boolean;
};

type Invitation = {
    ulid: string;
    email: string;
    created_at: string | null;
    expires_at: string;
    expired: boolean;
};

// Reaching this page at all already means the viewer is the owner — Equipa
// is gated by OrganizationInvitationPolicy::viewAny, owner-only. Any OTHER
// row is therefore someone the owner may remove or transfer responsibility
// to; the owner's own row never shows those actions.
const props = defineProps<{ members: Member[]; invitations: Invitation[] }>();

const form = useForm({ email: '' });

function invite(): void {
    form.post('/team/invitations', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function cancel(invitation: Invitation): void {
    if (!confirm(`Cancelar o convite para ${invitation.email}?`)) {
        return;
    }

    useForm({}).delete(`/team/invitations/${invitation.ulid}`, { preserveScroll: true });
}

// A double-click (or an impatient second click while the first request is
// still in flight) must not fire the mutation twice — the backend already
// guards this correctly under a row lock, but a confusing extra "não
// autorizado" from a stale second click is worth preventing here too.
const processingMemberId = ref<number | null>(null);

function remove(member: Member): void {
    if (processingMemberId.value !== null) {
        return;
    }

    if (!confirm(`Remover ${member.name} da organização? Deixa de ter acesso; os dados que já registou permanecem na organização.`)) {
        return;
    }

    processingMemberId.value = member.id;
    useForm({ member: member.id }).delete('/team/members', {
        preserveScroll: true,
        onFinish: () => (processingMemberId.value = null),
    });
}

function transferOwnership(member: Member): void {
    if (processingMemberId.value !== null) {
        return;
    }

    if (
        !confirm(
            `Transferir a responsabilidade da organização para ${member.name}? Deixa de ser o responsável e passa a membro; ${member.name} passa a decidir por esta organização.`,
        )
    ) {
        return;
    }

    processingMemberId.value = member.id;
    useForm({ member: member.id }).post('/team/members/transfer-ownership', {
        onFinish: () => (processingMemberId.value = null),
    });
}
</script>

<template>
    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading title="Equipa" description="Quem trabalha nesta organização, e quem ainda não respondeu ao convite." />

        <!-- Membros -->
        <section class="space-y-3 rounded-lg border border-border p-4">
            <h2 class="text-sm font-medium">Membros ({{ props.members.length }})</h2>

            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Nome</th>
                            <th class="px-3 py-2 font-medium">Email</th>
                            <th class="px-3 py-2 font-medium">Papel</th>
                            <th class="px-3 py-2 font-medium">Estado</th>
                            <th class="px-3 py-2 text-right font-medium">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="member in props.members" :key="member.email">
                            <td class="px-3 py-2 font-medium">{{ member.name }}</td>
                            <td class="px-3 py-2 text-muted-foreground">{{ member.email }}</td>
                            <td class="px-3 py-2">
                                <Badge :variant="member.is_owner ? 'default' : 'secondary'">
                                    {{ member.is_owner ? 'Responsável' : 'Membro' }}
                                </Badge>
                            </td>
                            <td class="px-3 py-2">
                                <span v-if="!member.active" class="text-xs text-red-600">desativado</span>
                                <span v-else class="text-xs text-emerald-600">ativo</span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <div v-if="!member.is_owner" class="flex justify-end gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Transferir responsabilidade"
                                        :disabled="processingMemberId !== null"
                                        @click="transferOwnership(member)"
                                    >
                                        <ArrowLeftRight class="size-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Remover da organização"
                                        :disabled="processingMemberId !== null"
                                        @click="remove(member)"
                                    >
                                        <UserMinus class="size-4" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Convidar -->
        <section class="space-y-3 rounded-lg border border-border p-4">
            <h2 class="text-sm font-medium">Convidar</h2>
            <form class="flex items-end gap-2" @submit.prevent="invite">
                <label class="block flex-1 text-sm">
                    <Label for="invite-email" class="mb-1 block font-medium">Email</Label>
                    <Input id="invite-email" v-model="form.email" type="email" placeholder="colega@escola.pt" />
                    <span v-if="form.errors.email" class="mt-1 block text-xs text-red-600">{{ form.errors.email }}</span>
                </label>
                <Button type="submit" :disabled="form.processing || form.email === ''">
                    <Mail class="size-4" /> Enviar convite
                </Button>
            </form>
        </section>

        <!-- Convites pendentes -->
        <section class="space-y-3 rounded-lg border border-border p-4">
            <h2 class="text-sm font-medium">Convites pendentes ({{ props.invitations.length }})</h2>

            <p v-if="props.invitations.length === 0" class="text-sm text-muted-foreground">Sem convites por responder.</p>

            <div v-else class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Email</th>
                            <th class="px-3 py-2 font-medium">Convidado em</th>
                            <th class="px-3 py-2 font-medium">Expira em</th>
                            <th class="px-3 py-2 font-medium">Estado</th>
                            <th class="px-3 py-2 text-right font-medium">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="invitation in props.invitations" :key="invitation.ulid">
                            <td class="px-3 py-2 font-medium">{{ invitation.email }}</td>
                            <td class="px-3 py-2 text-muted-foreground tabular-nums">{{ invitation.created_at }}</td>
                            <td class="px-3 py-2 text-muted-foreground tabular-nums">{{ invitation.expires_at }}</td>
                            <td class="px-3 py-2">
                                <span v-if="invitation.expired" class="text-xs text-amber-600">expirado</span>
                                <span v-else class="text-xs text-muted-foreground">pendente</span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Button variant="ghost" size="icon" aria-label="Cancelar convite" @click="cancel(invitation)">
                                    <X class="size-4" />
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-muted-foreground">
                Convidar de novo o mesmo email renova o convite existente — o link anterior deixa de funcionar.
            </p>
        </section>
    </div>
</template>
