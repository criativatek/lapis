<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Trash2, UserPlus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Student = {
    ulid: string;
    name: string;
    pseudonym: string;
    class_number: number | null;
    enrolled_on: string;
    is_late_entry: boolean;
    status_label: string;
};

const props = defineProps<{
    schoolClass: {
        ulid: string;
        label: string;
        subject: string;
        academic_year: string;
        grade_level: string | null;
        status_label: string;
        profile_name: string | null;
    };
    students: Student[];
}>();

// class_number is '' when empty (the backend treats empty as null); a plain
// null would not satisfy the Input's string|number model type.
const form = useForm<{ name: string; class_number: number | string; enrolled_on: string }>({
    name: '',
    class_number: '',
    enrolled_on: '',
});

function enroll(): void {
    form.post(`/classes/${props.schoolClass.ulid}/students`, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function remove(student: Student): void {
    if (confirm(`Remover ${student.name} da turma?`)) {
        router.delete(`/classes/${props.schoolClass.ulid}/students/${student.ulid}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="schoolClass.label" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <div class="flex items-start justify-between gap-3">
            <Heading :title="schoolClass.label" :description="`${schoolClass.subject} · ${schoolClass.academic_year}`" />
            <Badge variant="secondary">{{ schoolClass.status_label }}</Badge>
        </div>

        <p v-if="schoolClass.profile_name" class="text-sm text-muted-foreground">
            Avaliada por: <span class="font-medium text-foreground">{{ schoolClass.profile_name }}</span>
        </p>
        <p v-else class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">
            Esta turma ainda não tem perfil de avaliação associado.
        </p>

        <section class="space-y-3">
            <h2 class="text-sm font-semibold">Adicionar aluno</h2>
            <form class="grid items-end gap-3 rounded-lg border border-border p-4 sm:grid-cols-[1fr_6rem_auto_auto]" @submit.prevent="enroll">
                <div class="grid gap-1.5">
                    <Label for="name" class="text-xs">Nome</Label>
                    <Input id="name" v-model="form.name" placeholder="Nome do aluno" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="class_number" class="text-xs">Nº</Label>
                    <Input id="class_number" v-model.number="form.class_number" type="number" min="1" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="enrolled_on" class="text-xs">Entrada</Label>
                    <Input id="enrolled_on" v-model="form.enrolled_on" type="date" />
                </div>
                <Button type="submit" :disabled="form.processing"><UserPlus class="size-4" /> Inscrever</Button>
            </form>
            <p class="text-xs text-muted-foreground">
                O nome fica guardado de forma cifrada e separada. Só o código pseudónimo é usado no processamento por IA.
            </p>
        </section>

        <section v-if="students.length" class="overflow-hidden rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Nº</th>
                        <th class="px-4 py-2.5 font-medium">Nome</th>
                        <th class="px-4 py-2.5 font-medium">Pseudónimo</th>
                        <th class="px-4 py-2.5 font-medium">Entrada</th>
                        <th class="px-4 py-2.5 text-right font-medium">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="student in students" :key="student.ulid">
                        <td class="px-4 py-3 text-muted-foreground">{{ student.class_number ?? '—' }}</td>
                        <td class="px-4 py-3 font-medium">
                            {{ student.name }}
                            <Badge v-if="student.is_late_entry" variant="outline" class="ml-1.5">ingresso tardio</Badge>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-muted-foreground">{{ student.pseudonym }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ student.enrolled_on }}</td>
                        <td class="px-4 py-3 text-right">
                            <Button variant="ghost" size="icon" aria-label="Remover" @click="remove(student)">
                                <Trash2 class="size-4" />
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
