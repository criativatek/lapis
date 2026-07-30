<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

// Placeholder — Task 5 of docs/superpowers/plans/2026-07-29-instrument-editing.md
// builds the real editing form here. This exists only so the Vite manifest has
// an entry for 'instruments/Edit', which Inertia's full-page render (and this
// project's assertInertia()-based tests) requires even for a JSON-only request.
defineProps<{ instrument: { title: string } }>();
</script>

<template>
    <Head :title="`Editar ${instrument.title}`" />
    <div class="p-4 text-sm text-muted-foreground">A editar {{ instrument.title }}…</div>
</template>
