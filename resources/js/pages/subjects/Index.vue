<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { BookPlus, Pencil, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Subject = { ulid: string; name: string; code: string };

defineProps<{
    subjects: Subject[];
}>();

const open = ref(false);
const editing = ref<Subject | null>(null);

const form = useForm({ name: '', code: '' });

function openCreate(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    open.value = true;
}

function openEdit(subject: Subject): void {
    editing.value = subject;
    form.defaults({ name: subject.name, code: subject.code });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (editing.value) {
        form.put(`/subjects/${editing.value.ulid}`, options);
    } else {
        form.post('/subjects', options);
    }
}

function destroy(subject: Subject): void {
    if (confirm(`Eliminar a disciplina ${subject.name}?`)) {
        useForm({}).delete(`/subjects/${subject.ulid}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Disciplinas" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <div class="flex items-center justify-between">
            <Heading title="Disciplinas" description="As disciplinas que leciona." />
            <Button @click="openCreate">
                <BookPlus class="size-4" /> Nova disciplina
            </Button>
        </div>

        <div v-if="subjects.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <p class="text-sm text-muted-foreground">Ainda não tem disciplinas. Crie a primeira.</p>
        </div>

        <div v-else class="overflow-hidden rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Disciplina</th>
                        <th class="px-4 py-2.5 font-medium">Código</th>
                        <th class="px-4 py-2.5 text-right font-medium">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="subject in subjects" :key="subject.ulid">
                        <td class="px-4 py-3 font-medium">{{ subject.name }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ subject.code }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Button variant="ghost" size="icon" aria-label="Editar" @click="openEdit(subject)">
                                    <Pencil class="size-4" />
                                </Button>
                                <Button variant="ghost" size="icon" aria-label="Eliminar" @click="destroy(subject)">
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Dialog v-model:open="open">
            <DialogContent>
                <form @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>{{ editing ? 'Editar disciplina' : 'Nova disciplina' }}</DialogTitle>
                        <DialogDescription>Nome e código da disciplina.</DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-4">
                        <div class="grid gap-2">
                            <Label for="name">Nome</Label>
                            <Input id="name" v-model="form.name" placeholder="Português" />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="code">Código</Label>
                            <Input id="code" v-model="form.code" placeholder="PT7" />
                            <InputError :message="form.errors.code" />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" :disabled="form.processing">Guardar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
