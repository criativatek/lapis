<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ClipboardList, Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { statusToneClasses } from '@/lib/statusTone';

type Instrument = {
    ulid: string;
    title: string;
    class_label: string;
    type: string;
    period: string;
    applied_on: string;
    status_label: string;
    status: string;
    counts: boolean;
    items_count: number;
};

defineProps<{
    instruments: Instrument[];
}>();
</script>

<template>
    <Head title="Elementos de Avaliação" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <div class="flex items-center justify-between">
            <Heading title="Elementos de Avaliação" description="Testes, fichas, questões-aula e outras atividades de avaliação." />
            <Button as-child>
                <Link href="/instruments/create"><Plus class="size-4" /> Novo elemento de avaliação</Link>
            </Button>
        </div>

        <div v-if="instruments.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <ClipboardList class="mx-auto mb-3 size-8 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">
                Ainda não tem elementos de avaliação.
            </p>
            <Button as-child class="mt-3">
                <Link href="/instruments/create"><Plus class="size-4" /> Criar o primeiro</Link>
            </Button>
        </div>

        <div v-else class="overflow-hidden rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Elemento</th>
                        <th class="px-4 py-2.5 font-medium">Turma</th>
                        <th class="px-4 py-2.5 font-medium">Data</th>
                        <th class="px-4 py-2.5 font-medium">Estado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="instrument in instruments" :key="instrument.ulid" class="hover:bg-muted/30">
                        <td class="px-4 py-3">
                            <Link :href="instrument.status === 'draft' ? `/instruments/${instrument.ulid}/edit` : `/instruments/${instrument.ulid}`" class="font-medium hover:underline">
                                {{ instrument.title }}
                            </Link>
                            <Link v-if="instrument.status === 'draft'" :href="`/instruments/${instrument.ulid}/edit`" class="ml-2 text-xs text-primary hover:underline">Continuar preparação</Link>
                            <p class="text-xs text-muted-foreground">
                                {{ instrument.type }} · {{ instrument.items_count }} questões
                                <span v-if="!instrument.counts"> · não conta para a classificação</span>
                            </p>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">{{ instrument.class_label }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ instrument.applied_on }}</td>
                        <td class="px-4 py-3">
                            <!-- Tom pelo VALOR do estado (SUP-UEVAH4): distinguem-se sem ler. -->
                            <Badge variant="secondary" :class="statusToneClasses(instrument.status)">{{ instrument.status_label }}</Badge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
