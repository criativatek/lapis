import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    /**
     * A page title that already names the brand is used as it is.
     *
     * The landing page carries a complete, deliberately-sized title from
     * `App\Support\Seo\LandingSeo` — appending the app name to it produced
     * «… - LAPIS», which both overflowed the length a result page renders and
     * made the tab disagree with the server-rendered `<title>` a crawler
     * reads. Inertia's head manager runs this over a `<title>` CHILD of
     * `<Head>` too, not only over the `title` prop, so there is no way to opt
     * out from the component — it has to be decided here.
     */
    title: (title) => {
        if (!title) {
            return appName;
        }

        return /l[áa]pis/i.test(title) ? title : `${title} - ${appName}`;
    },
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            // As páginas legais são documentos públicos, e trazem o seu próprio
            // cabeçalho e rodapé. Sem este caso caíam no `default` e montavam o
            // AppLayout — o shell da aplicação autenticada, que lê
            // `auth.user.name` e rebenta quando não há sessão. Falhava só em
            // ecrãs largos, porque é aí que o menu de utilizador é desenhado.
            case name.startsWith('legal/'):
                return null;
            // A print/PDF view is a document, not a screen: no sidebar, no
            // nav, no app chrome of any kind. General on purpose, so the next
            // print view gets this for free without a second special case.
            case name.endsWith('/Print'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('admin/'):
                return AdminLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
