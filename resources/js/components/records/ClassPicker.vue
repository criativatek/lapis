<script setup lang="ts">
import { ChevronRight, Users } from '@lucide/vue';
import { computed } from 'vue';

/**
 * «Escolha a turma» — shown when no class is in the URL and none was chosen
 * before in this session. Never picks one for the teacher, not even when
 * there is a single class: the choice is one tap away and always explicit.
 */
type ClassOption = { ulid: string; label: string; subject: string; archived: boolean };

const props = defineProps<{
    classes: ClassOption[];
    /** Archived classes can be consulted (panorama) but never registered into. */
    includeArchived: boolean;
    title: string;
}>();

const emit = defineEmits<{ select: [ulid: string] }>();

const visible = computed(() => props.classes.filter((option) => props.includeArchived || !option.archived));
</script>

<template>
    <section class="space-y-3" aria-labelledby="class-picker-title" data-test="class-picker">
        <h2 id="class-picker-title" class="text-base font-semibold">{{ title }}</h2>
        <p v-if="visible.length === 0" class="rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
            Não há turmas ativas neste ano letivo.
        </p>
        <ul v-else class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <li v-for="option in visible" :key="option.ulid">
                <button
                    type="button"
                    class="card-soft flex min-h-11 w-full items-center gap-3 rounded-xl border border-border/70 bg-card px-4 py-3 text-left hover:bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :data-test="`pick-class-${option.ulid}`"
                    @click="emit('select', option.ulid)"
                >
                    <Users class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{ option.label }}</span>
                        <span class="block text-xs text-muted-foreground">{{ option.subject }}{{ option.archived ? ' · arquivada' : '' }}</span>
                    </span>
                    <ChevronRight class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                </button>
            </li>
        </ul>
    </section>
</template>
