<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Definições de aparência',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Definições de aparência" />

    <h1 class="sr-only">Definições de aparência</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Aparência"
            description="Alterar as definições de aparência da sua conta"
        />
        <AppearanceTabs />
    </div>
</template>
