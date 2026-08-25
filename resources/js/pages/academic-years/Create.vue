<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import Form from './Form.vue';

type Option = { value: string; label: string };

/**
 * CRIAR UM ANO LETIVO É O ANO E OS SEUS PERÍODOS, e mais nada.
 *
 * Os feriados e as interrupções não estão aqui de propósito: uma exceção letiva
 * pertence a um ano, e o ano tem de existir — gravado — antes de haver a que a
 * agarrar. Aparecem em «Editar ano letivo», assim que este passo terminar.
 */
defineProps<{
    statuses: Option[];
    periodKinds: Option[];
}>();
</script>

<template>
    <Head title="Novo ano letivo" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            title="Novo ano letivo"
            description="Defina o ano e os seus períodos de avaliação. Os feriados e as interrupções acrescentam-se a seguir, na página do ano."
        />
        <Form
            :statuses="statuses"
            :period-kinds="periodKinds"
            submit-url="/academic-years"
            method="post"
        />
    </div>
</template>
