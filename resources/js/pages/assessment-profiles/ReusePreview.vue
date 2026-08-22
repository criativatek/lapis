<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

interface Row { type: string; label: string; status: 'new' | 'existing' | 'conflict' | 'invalid' }
interface Profile { ulid: string; name: string; academic_year: string; subject: string }
interface AcademicYear { ulid: string; label: string }

const props = defineProps<{
    plan: { summary: Record<string, number>; rows: Row[] };
    sourceProfile: Profile;
    targetYear: AcademicYear;
    package: Record<string, unknown>;
}>();
const labels = { new: 'Novo', existing: 'Existente', conflict: 'Conflito', invalid: 'Inválido' };
const form = useForm({ package: JSON.stringify(props.package), reuse: '' });

function confirmReuse(): void {
    form.post(`/assessment-profiles/${props.sourceProfile.ulid}/reuse/confirm`);
}
</script>

<template>
    <Head title="Pré-visualizar reutilização" />
    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <div>
            <Heading title="Pré-visualizar reutilização" :description="`${sourceProfile.name} · ${sourceProfile.academic_year} → ${targetYear.label}`" />
            <Link :href="`/assessment-profiles/${sourceProfile.ulid}/reuse`" class="text-sm text-primary hover:underline">← Escolher outro ano</Link>
        </div>
        <div class="rounded-md border border-primary/25 bg-primary/5 p-4 text-sm font-medium">
            Será criada apenas a estrutura do perfil. O perfil de origem fica inalterado.
        </div>
        <div class="grid grid-cols-4 gap-3">
            <div v-for="status in ['new', 'existing', 'conflict', 'invalid']" :key="status" class="rounded border p-3 text-center">
                <strong class="block text-xl">{{ plan.summary[status] }}</strong>
                <span class="text-xs">{{ labels[status as keyof typeof labels] }}</span>
            </div>
        </div>
        <div class="divide-y rounded border">
            <div v-for="row in plan.rows" :key="`${row.type}-${row.label}`" class="flex items-center justify-between p-3">
                <span>{{ row.label }}</span>
                <span class="rounded bg-muted px-2 py-1 text-xs font-medium">{{ labels[row.status] }}</span>
            </div>
        </div>
        <p v-if="plan.summary.conflict" class="text-sm text-amber-700">Os conflitos serão ignorados. O perfil existente nunca é substituído.</p>
        <p v-if="form.errors.reuse || form.errors.package" class="text-sm text-destructive">{{ form.errors.reuse ?? form.errors.package }}</p>
        <Button type="button" :disabled="form.processing || plan.summary.new === 0" @click="confirmReuse">
            {{ form.processing ? 'A reutilizar…' : 'Reutilizar perfil' }}
        </Button>
    </div>
</template>
