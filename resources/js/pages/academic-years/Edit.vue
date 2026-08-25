<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import ExceptionsManager from './ExceptionsManager.vue';
import Form from './Form.vue';

type Option = { value: string; label: string };

type Period = {
    ulid?: string;
    label: string;
    kind: string;
    sequence: number;
    starts_on: string;
    ends_on: string;
};

/**
 * Um feriado, uma interrupção letiva ou um dia não letivo — já gravado, e por
 * isso sempre com ulid: só a página de edição as mostra, porque uma exceção
 * pertence a um ano letivo e o ano tem de existir antes de haver a que a
 * agarrar. É por isso que Create.vue não tem nada disto.
 */
type CalendarException = {
    ulid: string;
    type: string;
    title: string;
    starts_on: string;
    ends_on: string;
    note: string | null;
};

const props = defineProps<{
    academicYear: {
        ulid: string;
        label: string;
        starts_on: string;
        ends_on: string;
        status: string;
        country_code: string;
        region_code: string | null;
        editable: boolean;
        periods: Period[];
        exceptions: CalendarException[];
    };
    statuses: Option[];
    periodKinds: Option[];
    exceptionTypes: Option[];
    canManage: boolean;
}>();

/**
 * O QUE O FORMULÁRIO DO ANO GRAVA — e as exceções letivas já não estão aqui:
 * cada uma grava-se por si, em ExceptionsManager, com o seu próprio pedido.
 */
const initial = {
    label: props.academicYear.label,
    starts_on: props.academicYear.starts_on,
    ends_on: props.academicYear.ends_on,
    status: props.academicYear.status,
    country_code: props.academicYear.country_code,
    region_code: props.academicYear.region_code,
    periods: props.academicYear.periods,
};
</script>

<template>
    <Head :title="`Editar ${academicYear.label}`" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading
            :title="`Editar ${academicYear.label}`"
            description="Ajuste o ano letivo, os seus períodos e os dias em que não há aula."
        />

        <p
            v-if="!academicYear.editable"
            class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            Este ano letivo está encerrado e não pode ser alterado.
        </p>

        <p
            v-else-if="!canManage"
            class="rounded-md border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground"
        >
            O ano letivo é gerido pelo responsável da organização.
        </p>

        <!--
            DUAS SECÇÕES, DOIS BOTÕES DE GRAVAR, E É ISSO QUE SE QUER DIZER. Em
            cima o ano e os seus períodos, com o «Guardar ano letivo» que sempre
            teve. Em baixo os feriados e as interrupções, cada um com o seu — e
            fora do formulário do ano, e não dentro dele, porque de dentro dele
            eram uma promessa que aquele botão não cumpria.

            A mesma condição para as duas: um ano encerrado é só de leitura, e
            quem não gere a organização não reforma a estrutura do ano. É a
            mesma AcademicYearPolicy que o servidor volta a aplicar nas duas.
        -->
        <template v-else>
            <Form
                :statuses="statuses"
                :period-kinds="periodKinds"
                :initial="initial"
                :submit-url="`/academic-years/${academicYear.ulid}`"
                method="put"
            />

            <hr class="border-border" />

            <ExceptionsManager
                :academic-year="{
                    ulid: academicYear.ulid,
                    starts_on: academicYear.starts_on,
                    ends_on: academicYear.ends_on,
                }"
                :exceptions="academicYear.exceptions"
                :exception-types="exceptionTypes"
            />
        </template>
    </div>
</template>
