<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import TextLink from '@/components/TextLink.vue';
import { login } from '@/routes';

defineProps<{
    message: string;
}>();

defineOptions({
    layout: {
        title: 'Convite indisponível',
        description: '',
    },
});
</script>

<template>
    <Head title="Convite indisponível" />

    <div class="flex flex-col items-center gap-4 text-center">
        <ShieldAlert class="size-8 text-muted-foreground" />
        <p class="text-sm text-muted-foreground">{{ message }}</p>
        <TextLink :href="login()" class="text-sm underline underline-offset-4">Ir para a página de entrada</TextLink>
    </div>
</template>
