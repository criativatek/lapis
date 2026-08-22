<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

interface Profile { ulid: string; name: string; academic_year: string; subject: string }
interface AcademicYear { ulid: string; label: string }

const props = defineProps<{ profile: Profile; academicYears: AcademicYear[] }>();
const form = useForm({ target_academic_year: '' });

function submit(): void {
    form.post(`/assessment-profiles/${props.profile.ulid}/reuse/preview`);
}
</script>

<template>
    <Head title="Reutilizar perfil de avaliação" />
    <div class="mx-auto w-full max-w-2xl space-y-6 p-4">
        <div>
            <Heading title="Reutilizar perfil de avaliação" description="Crie uma cópia deste perfil para outro ano letivo." />
            <p class="mt-1 text-sm text-muted-foreground">{{ profile.name }} · {{ profile.subject }} · {{ profile.academic_year }}</p>
            <Link href="/assessment-profiles" class="text-sm text-primary hover:underline">← Voltar aos perfis</Link>
        </div>
        <div class="flex items-start gap-3 rounded-lg border border-primary/25 bg-primary/5 p-4 text-sm">
            <Info class="mt-0.5 size-4 shrink-0 text-primary" />
            <p>Será criada uma cópia independente, em rascunho. Não serão copiados alunos, classificações, registos pedagógicos ou outros dados pessoais.</p>
        </div>
        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <label for="target-academic-year" class="mb-2 block text-sm font-medium">Ano letivo de destino</label>
                <select id="target-academic-year" v-model="form.target_academic_year" class="w-full rounded-md border bg-background px-3 py-2" required>
                    <option value="" disabled>Escolha um ano letivo</option>
                    <option v-for="year in academicYears" :key="year.ulid" :value="year.ulid">{{ year.label }}</option>
                </select>
                <p v-if="form.errors.target_academic_year" class="mt-2 text-sm text-destructive">{{ form.errors.target_academic_year }}</p>
            </div>
            <Button type="submit" :disabled="form.processing || !form.target_academic_year">
                {{ form.processing ? 'A analisar…' : 'Pré-visualizar reutilização' }}
            </Button>
        </form>
    </div>
</template>
