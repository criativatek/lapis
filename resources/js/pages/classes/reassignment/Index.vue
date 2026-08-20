<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type ClassNeedingReassignment = {
    ulid: string;
    label: string;
    subject: string | null;
    academic_year: string;
};

type Member = {
    id: number;
    name: string;
    email: string;
};

const props = defineProps<{ classes: ClassNeedingReassignment[]; members: Member[] }>();

const selected = ref<Record<string, number | null>>({});

function assign(schoolClass: ClassNeedingReassignment): void {
    const memberId = selected.value[schoolClass.ulid];

    if (!memberId) {
        return;
    }

    useForm({ member: memberId }).post(`/classes/reassignment/${schoolClass.ulid}/assign`, { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            title="Turmas a reatribuir"
            description="Turmas sem professor ativo, depois de alguém sair ou ser removido da organização."
        />

        <p v-if="props.classes.length === 0" class="text-sm text-muted-foreground">Sem turmas por reatribuir.</p>

        <div v-else class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Turma</th>
                        <th class="px-3 py-2 font-medium">Disciplina</th>
                        <th class="px-3 py-2 font-medium">Ano letivo</th>
                        <th class="px-3 py-2 font-medium">Novo professor</th>
                        <th class="px-3 py-2 text-right font-medium">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="schoolClass in props.classes" :key="schoolClass.ulid">
                        <td class="px-3 py-2 font-medium">{{ schoolClass.label }}</td>
                        <td class="px-3 py-2 text-muted-foreground">{{ schoolClass.subject ?? '—' }}</td>
                        <td class="px-3 py-2 text-muted-foreground">{{ schoolClass.academic_year }}</td>
                        <td class="px-3 py-2">
                            <select
                                v-model="selected[schoolClass.ulid]"
                                class="w-full rounded-md border border-input bg-background px-2 py-1 text-sm"
                            >
                                <option :value="null">Escolher membro…</option>
                                <option v-for="member in props.members" :key="member.id" :value="member.id">
                                    {{ member.name }}
                                </option>
                            </select>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <Button
                                size="sm"
                                :disabled="!selected[schoolClass.ulid]"
                                @click="assign(schoolClass)"
                            >
                                Atribuir
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
