<script setup lang="ts">
import { ChevronDown, ChevronUp, GripVertical } from '@lucide/vue';
import { computed, ref } from 'vue';

export type OrderableSection = {
    key: string;
    heading: string;
    included: boolean;
};

const props = defineProps<{
    modelValue: OrderableSection[];
    disabled?: boolean;
    /** Whether each row offers an include/exclude checkbox. */
    toggleable?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [OrderableSection[]] }>();

/**
 * Reordering, by mouse AND by keyboard.
 *
 * DRAG AND DROP IS NEVER THE ONLY WAY (§10, §48). It is unusable with a
 * keyboard, hostile on a touch screen, and impossible with a screen reader —
 * so the up/down buttons are the primary control and the drag handle is the
 * shortcut. Both go through the same move(), so they cannot produce different
 * orders.
 *
 * NATIVE HTML5 DRAG EVENTS, no library. The list is a dozen rows that reorder
 * within themselves; a drag-and-drop dependency would be more code than this
 * whole file for a case it does not need to solve (§9).
 *
 * FOCUS SURVIVES THE MOVE. Pressing «mover para baixo» three times has to move
 * the same section three times, which means the button under the cursor must
 * still be the one that just moved — otherwise the second press moves whatever
 * fell into that slot. The row is re-focused by key after the list re-renders.
 */

const dragging = ref<number | null>(null);
const over = ref<number | null>(null);
/** Read out by assistive technology after each move. */
const announcement = ref('');

const rows = computed(() => props.modelValue);

function move(from: number, to: number) {
    if (props.disabled || to < 0 || to >= rows.value.length || from === to) {
        return;
    }

    const next = [...rows.value];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);

    emit('update:modelValue', next);

    announcement.value = `${moved.heading} passou para a posição ${to + 1} de ${next.length}.`;

    // The DOM has not re-rendered yet; wait a frame, then put focus back on the
    // control the teacher is holding down.
    requestAnimationFrame(() => {
        const button = document.querySelector<HTMLButtonElement>(
            `[data-order-key="${moved.key}"][data-order-direction="${to > from ? 'down' : 'up'}"]`,
        );

        button?.focus();
    });
}

function toggle(index: number) {
    if (props.disabled) {
        return;
    }

    const next = [...rows.value];
    next[index] = { ...next[index], included: !next[index].included };
    emit('update:modelValue', next);
}

// ------------------------------------------------------------------- dragging

function onDragStart(index: number, event: DragEvent) {
    if (props.disabled) {
        return;
    }

    dragging.value = index;
    event.dataTransfer?.setData('text/plain', String(index));

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
    }
}

function onDragOver(index: number, event: DragEvent) {
    if (props.disabled || dragging.value === null) {
        return;
    }

    event.preventDefault();
    over.value = index;
}

function onDrop(index: number) {
    if (dragging.value !== null) {
        move(dragging.value, index);
    }

    dragging.value = null;
    over.value = null;
}

function onDragEnd() {
    dragging.value = null;
    over.value = null;
}
</script>

<template>
    <div>
        <!-- One live region for the whole list: each move replaces its text, so
             a screen reader announces the new position without the buttons
             needing labels that change. -->
        <p class="sr-only" aria-live="polite">{{ announcement }}</p>

        <ul class="divide-y divide-border overflow-hidden rounded-lg border border-border">
            <li
                v-for="(section, index) in rows"
                :key="section.key"
                class="flex items-center gap-2 px-3 py-2"
                :class="[
                    dragging === index ? 'opacity-50' : '',
                    over === index && dragging !== null && dragging !== index ? 'bg-primary/5' : '',
                    section.included ? '' : 'bg-muted/30',
                ]"
                :draggable="!disabled"
                @dragstart="onDragStart(index, $event)"
                @dragover="onDragOver(index, $event)"
                @drop="onDrop(index)"
                @dragend="onDragEnd"
            >
                <GripVertical
                    v-if="!disabled"
                    class="size-4 shrink-0 cursor-grab text-muted-foreground"
                    aria-hidden="true"
                />

                <label v-if="toggleable" class="flex min-w-0 flex-1 items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        class="size-4 shrink-0"
                        :checked="section.included"
                        :disabled="disabled"
                        @change="toggle(index)"
                    />
                    <span :class="section.included ? '' : 'text-muted-foreground'">{{ section.heading }}</span>
                </label>

                <span v-else class="min-w-0 flex-1 text-sm">{{ section.heading }}</span>

                <span class="shrink-0 text-xs tabular-nums text-muted-foreground">
                    {{ index + 1 }}/{{ rows.length }}
                </span>

                <div v-if="!disabled" class="flex shrink-0 items-center">
                    <button
                        type="button"
                        class="rounded p-1 text-muted-foreground hover:bg-muted disabled:opacity-30"
                        :data-order-key="section.key"
                        data-order-direction="up"
                        :disabled="index === 0"
                        :aria-label="`Mover «${section.heading}» para cima`"
                        @click="move(index, index - 1)"
                    >
                        <ChevronUp class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="rounded p-1 text-muted-foreground hover:bg-muted disabled:opacity-30"
                        :data-order-key="section.key"
                        data-order-direction="down"
                        :disabled="index === rows.length - 1"
                        :aria-label="`Mover «${section.heading}» para baixo`"
                        @click="move(index, index + 1)"
                    >
                        <ChevronDown class="size-4" />
                    </button>
                </div>
            </li>
        </ul>
    </div>
</template>
