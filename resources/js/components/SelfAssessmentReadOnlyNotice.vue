<script setup lang="ts">
defineProps<{
    /** The student is told to try again later; the teacher is only told it is not possible now. */
    audience: 'teacher' | 'student';
}>();
</script>

<template>
    <p
        role="status"
        class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
        data-test="read-only-notice"
    >
        A autoavaliação está temporariamente só de leitura. As respostas guardadas continuam visíveis, mas
        <template v-if="audience === 'student'">não é possível guardar por agora. Tenta mais tarde.</template>
        <template v-else>por agora não é possível guardar alterações.</template>
    </p>
</template>
