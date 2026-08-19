<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import type { OrderableSection } from '@/components/reports/SectionOrderList.vue';
import SectionOrderList from '@/components/reports/SectionOrderList.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string; description?: string };

type SectionRow = {
    key: string;
    heading: string;
    included: boolean;
    needs_teacher_input: boolean;
    may_name_students: boolean;
};

type TemplatePayload = {
    ulid: string;
    name: string;
    description: string | null;
    kind: string;
    kind_label: string;
    report_type: string;
    report_type_label: string;
    tone: string;
    options: Record<string, unknown>;
    is_default: boolean;
    is_active: boolean;
};

const props = defineProps<{
    template: TemplatePayload;
    sections: SectionRow[];
    tones: Option[];
    can: { update: boolean };
}>();

const name = ref(props.template.name);
const description = ref(props.template.description ?? '');
const tone = ref(props.template.tone);
const isActive = ref(props.template.is_active);
const isDefault = ref(props.template.is_default);
const nameStudents = ref(props.template.options.name_students === true);

const order = ref<OrderableSection[]>(
    props.sections.map((section) => ({
        key: section.key,
        heading: section.heading,
        included: section.included,
    })),
);

const saving = ref(false);

const dirty = computed(() => {
    if (
        name.value !== props.template.name ||
        description.value !== (props.template.description ?? '') ||
        tone.value !== props.template.tone ||
        isActive.value !== props.template.is_active ||
        isDefault.value !== props.template.is_default ||
        nameStudents.value !== (props.template.options.name_students === true)
    ) {
        return true;
    }

    return order.value.some(
        (section, index) =>
            section.key !== props.sections[index]?.key || section.included !== props.sections[index]?.included,
    );
});

function save() {
    saving.value = true;

    router.put(
        `/reports/modelos/${props.template.ulid}`,
        {
            name: name.value,
            description: description.value === '' ? null : description.value,
            tone: tone.value,
            is_active: isActive.value,
            is_default: isDefault.value,
            name_students: nameStudents.value,
            sections: order.value.map((section) => ({ key: section.key, included: section.included })),
        },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

const namesStudentsSections = computed(() =>
    props.sections.filter(
        (section) => section.may_name_students && order.value.find((row) => row.key === section.key)?.included,
    ),
);
</script>

<template>
    <Head :title="template.name" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Link href="/reports/modelos" class="text-sm text-muted-foreground hover:underline">← Modelos</Link>

        <Heading
            :title="template.name"
            :description="`${template.report_type_label} · ${template.kind_label}`"
        />

        <div v-if="!can.update" class="flex items-start gap-3 rounded-lg border border-border bg-muted/30 p-4 text-sm">
            <Lock class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            <p class="text-muted-foreground">
                Este modelo é só de leitura. Pode duplicá-lo a partir da lista para criar uma versão sua.
            </p>
        </div>

        <template v-else>
            <section class="grid gap-4 rounded-lg border border-border p-4 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="name">Nome</Label>
                    <Input id="name" v-model="name" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="description">Descrição</Label>
                    <Input id="description" v-model="description" />
                </div>

                <div v-if="tones.length > 1" class="grid gap-2">
                    <Label for="tone">Registo</Label>
                    <select
                        id="tone"
                        v-model="tone"
                        class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                    >
                        <option v-for="option in tones" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div class="flex flex-col justify-end gap-2 text-sm">
                    <label class="flex items-center gap-2">
                        <input v-model="isDefault" type="checkbox" class="size-4" />
                        Usar por omissão neste tipo de relatório
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="isActive" type="checkbox" class="size-4" />
                        Ativo
                    </label>
                </div>
            </section>

            <section class="space-y-3">
                <div>
                    <h2 class="font-medium">Secções e ordem</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Arraste, ou use as setas de cada linha. Um modelo nunca concede secções que o plano da escola
                        não inclua.
                    </p>
                </div>

                <SectionOrderList v-model="order" toggleable />

                <label
                    v-if="namesStudentsSections.length > 0"
                    class="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm"
                >
                    <input v-model="nameStudents" type="checkbox" class="mt-0.5 size-4" />
                    <span>
                        <span class="block font-medium">Permitir identificar alunos pelo nome</span>
                        <span class="block text-xs text-muted-foreground">
                            Aplica-se aos relatórios criados a partir deste modelo. Pode sempre ser alterado em cada
                            relatório.
                        </span>
                    </span>
                </label>
            </section>

            <div class="flex flex-wrap items-center gap-3 border-t border-border pt-6">
                <Button :disabled="!dirty || saving" @click="save">Guardar</Button>
                <span v-if="dirty" class="text-xs text-amber-700 dark:text-amber-400">Alterações por guardar.</span>
                <span v-else class="text-xs text-muted-foreground">Guardado.</span>
            </div>
        </template>
    </div>
</template>
