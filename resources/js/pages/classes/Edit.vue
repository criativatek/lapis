<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type SchoolClass = {
    ulid: string;
    label: string;
    is_support_class: boolean;
    subject: string;
    academic_year: string;
    grade_level: string | null;
};

const props = defineProps<{
    schoolClass: SchoolClass;
}>();

const form = useForm({
    label: props.schoolClass.label,
    is_support_class: props.schoolClass.is_support_class,
});

function submit(): void {
    form.put(`/classes/${props.schoolClass.ulid}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Editar ${schoolClass.label}`" />

    <div class="mx-auto w-full max-w-2xl space-y-6 p-4">
        <Heading title="Editar turma" description="Só a designação e o tipo de turma podem ser alterados aqui." />

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="label">Designação</Label>
                <Input id="label" v-model="form.label" placeholder="Ex.: 7.º A" />
                <InputError :message="form.errors.label" />
            </div>

            <div class="flex items-start gap-3 rounded-lg border border-border p-3">
                <Checkbox
                    id="is_support_class"
                    :model-value="form.is_support_class"
                    class="mt-0.5"
                    @update:model-value="form.is_support_class = $event === true"
                />
                <div class="grid gap-1">
                    <Label for="is_support_class">Turma de apoio</Label>
                    <p class="text-xs text-muted-foreground">
                        Pode reunir alunos de várias turmas. Desligar não remove nenhum aluno — só deixa de mostrar a pesquisa de alunos existentes.
                    </p>
                </div>
            </div>

            <div class="grid gap-4 rounded-lg border border-dashed border-border p-4 sm:grid-cols-3">
                <div>
                    <p class="text-xs font-medium text-muted-foreground">Ano letivo</p>
                    <p class="text-sm">{{ schoolClass.academic_year }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-muted-foreground">Disciplina</p>
                    <p class="text-sm">{{ schoolClass.subject }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-muted-foreground">Ano de escolaridade</p>
                    <p class="text-sm">{{ schoolClass.grade_level ?? '—' }}</p>
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                Ano letivo, disciplina e ano de escolaridade não podem ser alterados depois de a turma ser criada — determinam os elementos de avaliação e os relatórios já associados.
            </p>

            <div class="flex items-center gap-2">
                <Button type="submit" :disabled="form.processing">Guardar</Button>
                <Button as-child variant="ghost">
                    <Link :href="`/classes/${schoolClass.ulid}`">Cancelar</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
