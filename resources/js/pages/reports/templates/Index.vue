<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ChevronRight, Copy, LayoutTemplate, Plus } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type TemplateRow = {
    ulid: string;
    name: string;
    description: string | null;
    kind: string;
    kind_label: string;
    report_type: string;
    report_type_label: string;
    author: string | null;
    is_default: boolean;
    is_active: boolean;
    updated_at: string;
    sections: number;
    can: { update: boolean; duplicate: boolean };
};

const props = defineProps<{
    templates: TemplateRow[];
    canCreate: { personal: boolean; institutional: boolean };
    reportTypes: Option[];
}>();

const creating = ref(false);

const form = useForm({
    kind: props.canCreate.personal ? 'personal' : 'institutional',
    report_type: props.reportTypes[0]?.value ?? 'class',
    name: '',
    description: '',
});

function create() {
    form.post('/reports/modelos');
}

function duplicate(template: TemplateRow) {
    router.post(`/reports/modelos/${template.ulid}/duplicar`, {});
}

const dateFormatter = new Intl.DateTimeFormat('pt-PT', { day: '2-digit', month: 'short', year: 'numeric' });

function formatDate(value: string): string {
    return dateFormatter.format(new Date(value));
}

const badgeClass: Record<string, string> = {
    system: 'bg-muted text-muted-foreground',
    personal: 'bg-blue-500/10 text-blue-700 dark:text-blue-400',
    institutional: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
};
</script>

<template>
    <Head title="Modelos de relatório" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <Link href="/reports" class="text-sm text-muted-foreground hover:underline">← Relatórios</Link>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Modelos de relatório"
                description="A organização de um relatório, guardada para a próxima vez. Um modelo não guarda dados de nenhuma turma."
            />

            <Button
                v-if="(canCreate.personal || canCreate.institutional) && !creating"
                @click="creating = true"
            >
                <Plus class="size-4" />
                Novo modelo
            </Button>
        </div>

        <section v-if="creating" class="space-y-4 rounded-lg border border-border p-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="name">Nome</Label>
                    <Input id="name" v-model="form.name" placeholder="Ex.: Relatório semestral de Português" />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="report-type">Tipo de relatório</Label>
                    <select
                        id="report-type"
                        v-model="form.report_type"
                        class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                    >
                        <option v-for="type in reportTypes" :key="type.value" :value="type.value">
                            {{ type.label }}
                        </option>
                    </select>
                </div>

                <div v-if="canCreate.institutional" class="grid gap-2">
                    <Label for="kind">Disponível para</Label>
                    <select
                        id="kind"
                        v-model="form.kind"
                        class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                    >
                        <option v-if="canCreate.personal" value="personal">Apenas para mim</option>
                        <option value="institutional">Toda a escola</option>
                    </select>
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="description">Descrição (opcional)</Label>
                    <Input id="description" v-model="form.description" />
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button :disabled="form.processing || form.name.trim() === ''" @click="create">Criar</Button>
                <Button variant="ghost" @click="creating = false">Cancelar</Button>
            </div>
        </section>

        <div v-if="templates.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <LayoutTemplate class="mx-auto mb-3 size-8 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">Ainda não há modelos disponíveis.</p>
        </div>

        <ul v-else class="divide-y divide-border overflow-hidden rounded-lg border border-border">
            <li v-for="template in templates" :key="template.ulid" class="flex items-center gap-3 px-4 py-3">
                <component
                    :is="template.can.update ? Link : 'div'"
                    :href="template.can.update ? `/reports/modelos/${template.ulid}` : undefined"
                    class="min-w-0 flex-1"
                    :class="template.can.update ? 'hover:underline' : ''"
                >
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="font-medium" :class="template.is_active ? '' : 'text-muted-foreground'">
                            {{ template.name }}
                        </span>
                        <span
                            class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="badgeClass[template.kind]"
                        >
                            {{ template.kind_label }}
                        </span>
                        <span
                            v-if="template.is_default"
                            class="rounded-full bg-amber-500/10 px-2 py-0.5 text-[11px] text-amber-700 dark:text-amber-400"
                        >
                            Predefinido
                        </span>
                        <span
                            v-if="!template.is_active"
                            class="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground"
                        >
                            Desativado
                        </span>
                    </span>
                    <span class="mt-0.5 block text-sm break-words text-muted-foreground">
                        {{ template.report_type_label }} · {{ template.sections }} secções
                        <template v-if="template.author"> · {{ template.author }}</template>
                        · {{ formatDate(template.updated_at) }}
                    </span>
                </component>

                <Button
                    v-if="template.can.duplicate"
                    variant="ghost"
                    size="sm"
                    :aria-label="`Duplicar «${template.name}»`"
                    @click="duplicate(template)"
                >
                    <Copy class="size-3.5" />
                </Button>

                <ChevronRight v-if="template.can.update" class="size-4 shrink-0 text-muted-foreground" />
            </li>
        </ul>

        <p class="text-xs text-muted-foreground">
            Alterar um modelo não altera relatórios já criados. Cada relatório guarda a estrutura com que nasceu.
        </p>
    </div>
</template>
