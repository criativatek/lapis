<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import LandingFooter from '@/components/landing/LandingFooter.vue';
import LandingHeader from '@/components/landing/LandingHeader.vue';
import { useLightThemeLock } from '@/composables/useLightThemeLock';

/**
 * The frame every public page shares: skip link, header, main, footer, and
 * the light-theme lock. The pages themselves are composed from the pieces in
 * this folder; nothing here knows which page it is wrapping.
 */

defineProps<{ contactEmail?: string | null }>();

const page = usePage();
const authenticated = computed(() => page.props.auth.user !== null);

useLightThemeLock();
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <a
            href="#conteudo"
            class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[60] focus:rounded-md focus:bg-blue-600 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white"
        >
            Saltar para o conteúdo
        </a>

        <LandingHeader :authenticated="authenticated" />

        <main id="conteudo">
            <slot :authenticated="authenticated" />
        </main>

        <LandingFooter
            :authenticated="authenticated"
            :contact-email="contactEmail ?? null"
        />
    </div>
</template>
