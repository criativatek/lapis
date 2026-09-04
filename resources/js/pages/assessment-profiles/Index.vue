<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Copy, Pencil, Plus, Trash2 } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import TableShell from '@/components/TableShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { statusToneClasses } from '@/lib/statusTone';

type Profile = {
    ulid: string;
    name: string;
    subject: string;
    academic_year: string;
    grade_levels: string[];
    grade_levels_label: string;
    is_active: boolean;
    status_label: string;
    has_draft: boolean;
};

defineProps<{
    profiles: Profile[];
    canManage: boolean;
}>();

function activate(profile: Profile): void {
    useForm({}).post(`/assessment-profiles/${profile.ulid}/activate`, { preserveScroll: true });
}

function destroy(profile: Profile): void {
    if (confirm(`Eliminar o perfil ${profile.name}?`)) {
        router.delete(`/assessment-profiles/${profile.ulid}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Perfis de Avaliação" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <div class="flex items-center justify-between">
            <Heading title="Perfis de Avaliação" description="Como cada disciplina e ano são avaliados." />
            <Button v-if="canManage" as-child>
                <Link href="/assessment-profiles/create"><Plus class="size-4" /> Novo perfil</Link>
            </Button>
        </div>

        <EmptyState
            v-if="profiles.length === 0"
            title="Ainda não tem perfis de avaliação. Crie o primeiro para definir domínios e ponderações."
        >
            <template v-if="canManage" #action>
                <Button as-child>
                    <Link href="/assessment-profiles/create"><Plus class="size-4" /> Criar o primeiro perfil</Link>
                </Button>
            </template>
        </EmptyState>

        <TableShell v-else>
            <template #head>
                <tr>
                    <th class="px-4 py-2.5 font-medium">Perfil</th>
                    <th class="px-4 py-2.5 font-medium">Contexto</th>
                    <th class="px-4 py-2.5 font-medium">Estado</th>
                    <th class="px-4 py-2.5 text-right font-medium">Ações</th>
                </tr>
            </template>
            <template #body>
                <tr v-for="profile in profiles" :key="profile.ulid">
                        <td class="px-4 py-3 font-medium">{{ profile.name }}</td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ profile.subject }} · {{ profile.academic_year }}<template v-if="profile.grade_levels_label"> · {{ profile.grade_levels_label }}</template>
                        </td>
                        <td class="px-4 py-3">
                            <Badge variant="secondary" :class="statusToneClasses(profile.is_active ? 'active' : 'archived')">{{ profile.status_label }}</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Button
                                    v-if="canManage && profile.has_draft"
                                    variant="ghost"
                                    size="sm"
                                    class="text-emerald-700 hover:text-emerald-800"
                                    @click="activate(profile)"
                                >
                                    <CheckCircle2 class="size-4" /> Ativar
                                </Button>
                                <Button as-child variant="ghost" size="icon" aria-label="Editar">
                                    <Link :href="`/assessment-profiles/${profile.ulid}/edit`"><Pencil class="size-4" /></Link>
                                </Button>
                                <Button v-if="canManage" as-child variant="ghost" size="sm" title="Reutilizar noutro ano letivo" aria-label="Reutilizar noutro ano letivo">
                                    <Link :href="`/assessment-profiles/${profile.ulid}/reuse`"><Copy class="size-4" /> Reutilizar</Link>
                                </Button>
                                <Button
                                    v-if="canManage && !profile.is_active"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Eliminar"
                                    @click="destroy(profile)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </td>
                    </tr>
            </template>
        </TableShell>
    </div>
</template>
