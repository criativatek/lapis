<script setup lang="ts">
import { User } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';
import { computed, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

/**
 * A student's photo as a small identifying thumbnail.
 *
 * Purely a visual aid to put a face to a name — it carries no pedagogical
 * meaning, and the name always sits beside it. It is decorative for screen
 * readers (empty alt, aria-hidden) precisely because the name is right there:
 * announcing "photo of Álvaro Simões, Álvaro Simões" helps nobody.
 *
 * The image never travels in the payload — photoUrl points at the authorized
 * route, which checks the Policy before streaming a single byte.
 *
 * Built on a plain <img> rather than components/ui/avatar: that one loads
 * through `new window.Image()` in JS, which ignores loading="lazy". A class
 * grid shows every student at once, so native lazy loading is worth more here
 * than the shared primitive.
 */
const props = withDefaults(
    defineProps<{
        photoUrl?: string | null;
        /** xs (24px) for dense assessment grids, md (34px) for the roster. */
        size?: 'xs' | 'md';
        class?: HTMLAttributes['class'];
        zoomable?: boolean;
        studentName?: string;
    }>(),
    {
        photoUrl: null,
        size: 'md',
        class: undefined,
        zoomable: false,
        studentName: '',
    },
);

// The grid size is deliberately smaller than the roster's: the photo adapts to
// the density of the grid, never the other way round.
const boxClass = computed(() =>
    props.size === 'xs' ? 'size-6' : 'size-[34px]',
);

// cn() merges through tailwind-merge, so a caller passing `hidden sm:inline-flex`
// actually overrides the base display instead of racing it in the stylesheet.
const classes = computed(() =>
    cn(
        'inline-flex shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-muted align-middle',
        boxClass.value,
        props.class,
    ),
);

// A photo that 404s or fails to decode must not leave a broken image in the
// table — it falls back to the same placeholder as a student who has none.
const failed = ref(false);
const dialogOpen = ref(false);
const triggerButton = ref<HTMLButtonElement | null>(null);

const handlePhotoError = () => {
    failed.value = true;
    dialogOpen.value = false;
};

const focusTrigger = () => {
    triggerButton.value?.focus();
};

const onDialogOpenChange = (open: boolean) => {
    dialogOpen.value = open;

    if (!open) {
        window.setTimeout(focusTrigger, 0);
    }
};

const onDialogCloseAutoFocus = (event: Event) => {
    event.preventDefault();
    focusTrigger();
};

// A replaced photo arrives as a new URL; give it a fresh chance to load.
watch(
    () => props.photoUrl,
    () => {
        failed.value = false;
    },
);

const showPhoto = computed(() => Boolean(props.photoUrl) && !failed.value);
</script>

<template>
    <Dialog
        v-if="zoomable && showPhoto"
        :open="dialogOpen"
        @update:open="onDialogOpenChange"
    >
        <button
            ref="triggerButton"
            type="button"
            :class="classes"
            :aria-expanded="dialogOpen"
            aria-haspopup="dialog"
            :aria-label="`Ampliar fotografia de ${studentName}`"
            @click="dialogOpen = true"
            @keydown.enter.prevent="dialogOpen = true"
            @keydown.space.prevent="dialogOpen = true"
        >
            <img
                :src="photoUrl!"
                alt=""
                loading="lazy"
                decoding="async"
                class="size-full object-cover"
                @error="handlePhotoError"
            />
        </button>
        <DialogContent
            class="flex max-h-[calc(100vh-5rem)] max-w-[calc(100vw-2rem)] items-center justify-center overflow-hidden p-12"
            @close-auto-focus="onDialogCloseAutoFocus"
        >
            <DialogTitle class="sr-only">
                Fotografia de {{ studentName }}
            </DialogTitle>
            <DialogDescription class="sr-only">
                Pré-visualização da fotografia do aluno.
            </DialogDescription>
            <img
                :src="photoUrl!"
                :alt="`Fotografia de ${studentName}`"
                class="max-h-[calc(100vh-5rem)] max-w-[calc(100vw-2rem)] object-contain"
                @error="handlePhotoError"
            />
        </DialogContent>
    </Dialog>
    <span v-else :class="classes" aria-hidden="true">
        <img
            v-if="showPhoto"
            :src="photoUrl!"
            alt=""
            loading="lazy"
            decoding="async"
            class="size-full object-cover"
            @error="handlePhotoError"
        />
        <User
            v-else
            :class="size === 'xs' ? 'size-3' : 'size-4'"
            class="text-muted-foreground"
        />
    </span>
</template>
