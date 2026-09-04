<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarClock, FileUp, Plus } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type ClassOption = {
    ulid: string;
    label: string;
    subject: string;
};

defineProps<{
    classes: ClassOption[];
}>();
</script>

<template>
    <Head title="Configurar horários" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <Heading
            title="Configurar horários"
            description="Duas formas de preencher o horário das tuas turmas — escolhe a que for mais rápida."
        />

        <div class="grid gap-3 sm:grid-cols-2">
            <section class="flex flex-col gap-3 rounded-lg border border-border p-4">
                <div>
                    <h2 class="text-base font-semibold">Importar horário do professor</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Carrega o PDF do teu horário e as aulas ficam associadas às turmas certas, prontas a rever antes de guardar.
                    </p>
                </div>
                <Button as-child class="mt-auto self-start">
                    <Link href="/timetable-imports/create"><FileUp class="size-4" /> Importar PDF</Link>
                </Button>
            </section>

            <section class="flex flex-col gap-3 rounded-lg border border-border p-4">
                <div>
                    <h2 class="text-base font-semibold">Configurar manualmente</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Define os dias e horas de cada turma diretamente na sua página.
                    </p>
                </div>

                <EmptyState v-if="classes.length === 0" title="Ainda não existem turmas para configurar.">
                    <template #action>
                        <Button as-child>
                            <Link href="/classes/create"><Plus class="size-4" /> Nova turma</Link>
                        </Button>
                    </template>
                </EmptyState>

                <div v-else class="space-y-2">
                    <Link
                        v-for="schoolClass in classes"
                        :key="schoolClass.ulid"
                        :href="`/classes/${schoolClass.ulid}#horario`"
                        class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-md border border-border px-3 py-2 text-sm transition-colors hover:border-primary/40"
                    >
                        <span>
                            <span class="font-medium">{{ schoolClass.label }}</span>
                            <span class="text-muted-foreground"> · {{ schoolClass.subject }}</span>
                        </span>
                        <span class="flex shrink-0 items-center gap-1.5 text-muted-foreground">
                            <CalendarClock class="size-4" /> Configurar horário
                        </span>
                    </Link>
                </div>
            </section>
        </div>
    </div>
</template>
