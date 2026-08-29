import { onUnmounted } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';

/**
 * For the pages the server locks to the light theme (app.blade.php sets
 * `data-theme-lock="light"` on <html>): when the visitor leaves for the
 * application through an Inertia visit — no reload, so the attribute would
 * otherwise survive — lift the lock and let their real preference apply.
 */
export function useLightThemeLock(): void {
    onUnmounted(() => {
        delete document.documentElement.dataset.themeLock;
        initializeTheme();
    });
}
