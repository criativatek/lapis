<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ClipboardList, Plus } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import TableShell from '@/components/TableShell.vue';
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
        <PageHeader title="Elementos de Avaliação" description="Testes, fichas, questões-aula e outras atividades de avaliação.">
            <template #actions>
                <Button as-child>
                    <Link href="/instruments/create"><Plus class="size-4" /> Novo elemento de avaliação</Link>
                </Button>
            </template>
        </PageHeader>

        <EmptyState v-if="instruments.length === 0" title="Ainda não tem elementos de avaliação." :icon="ClipboardList">
            <template #action>
                <Button as-child>
                    <Link href="/instruments/create"><Plus class="size-4" /> Criar o primeiro</Link>
                </Button>
            </template>
        </EmptyState>

        <TableShell v-else>
            <template #head>
                <tr>
                        <th class="px-4 py-2.5 font-medium">Elemento</th>
                        <th class="px-4 py-2.5 font-medium">Turma</th>
                        <th class="px-4 py-2.5 font-medium">Data</th>
                        <th class="px-4 py-2.5 font-medium">Estado</th>
                </tr>
            </template>
            <template #body>
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
            </template>
        </TableShell>
    </div>
</template>
