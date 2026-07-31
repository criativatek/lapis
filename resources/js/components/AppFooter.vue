<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';

const version = usePage().props.appVersion;
</script>

<template>
    <footer class="flex items-center justify-between border-t border-border px-4 py-3 text-xs text-muted-foreground">
        <span>LÁPIS v{{ version }}</span>
        <Link href="/novidades" class="hover:underline">Novidades</Link>
    </footer>
</template>
