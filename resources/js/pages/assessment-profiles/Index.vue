<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Pencil, Plus, Trash2 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Profile = {
    ulid: string;
    name: string;
    subject: string;
    academic_year: string;
    grade_level: string | null;
    is_active: boolean;
    status_label: string;
    has_draft: boolean;
};

defineProps<{
    profiles: Profile[];
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
            <Button as-child>
                <Link href="/assessment-profiles/create"><Plus class="size-4" /> Novo perfil</Link>
            </Button>
        </div>

        <div v-if="profiles.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <p class="text-sm text-muted-foreground">
                Ainda não tem perfis de avaliação. Crie o primeiro para definir domínios e ponderações.
            </p>
        </div>

        <div v-else class="overflow-hidden rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Perfil</th>
                        <th class="px-4 py-2.5 font-medium">Contexto</th>
                        <th class="px-4 py-2.5 font-medium">Estado</th>
                        <th class="px-4 py-2.5 text-right font-medium">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="profile in profiles" :key="profile.ulid">
                        <td class="px-4 py-3 font-medium">{{ profile.name }}</td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ profile.subject }} · {{ profile.academic_year }}<template v-if="profile.grade_level"> · {{ profile.grade_level }}</template>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="profile.is_active ? 'default' : 'secondary'">{{ profile.status_label }}</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Button
                                    v-if="profile.has_draft"
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
                                <Button
                                    v-if="!profile.is_active"
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
                </tbody>
            </table>
        </div>
    </div>
</template>
