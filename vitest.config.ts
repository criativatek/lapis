import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        environmentOptions: {
            jsdom: {
                // localStorage is origin-scoped; jsdom's default document URL
                // (about:blank) has no origin, so it never exposes storage.
                url: 'http://localhost/',
            },
        },
        setupFiles: ['./vitest.setup.ts'],
        globals: false,
    },
});
