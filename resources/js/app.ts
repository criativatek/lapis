import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import { formatTitle, resolveLayout } from '@/inertia';
import { initializeFlashToast } from '@/lib/flashToast';

createInertiaApp({
    // Shared with ssr.ts — see resources/js/inertia.ts for the reasoning.
    title: formatTitle,
    layout: resolveLayout,
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
