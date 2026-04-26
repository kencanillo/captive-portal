import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, Fragment, h } from 'vue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    async setup({ el, App, props, plugin }) {
        const isPublicPage = String(props.initialPage?.component || '').startsWith('Public/');

        window.appToast = (message, tone = 'info') => {
            window.dispatchEvent(new CustomEvent('app:toast', {
                detail: { message, tone },
            }));
        };

        const extraNodes = [];
        const app = createApp({
            render: () => h(Fragment, [h(App, props), ...extraNodes]),
        }).use(plugin);

        if (!isPublicPage) {
            const [{ ZiggyVue }, { default: AppToastStack }] = await Promise.all([
                import('ziggy-js'),
                import('@/Components/AppToastStack.vue'),
            ]);

            app.use(ZiggyVue);
            extraNodes.push(h(AppToastStack));
        }

        const mountedApp = app.mount(el);

        document.documentElement.setAttribute('data-app-mounted', 'true');
        document.querySelector('[data-portal-shell]')?.remove();

        return mountedApp;
    },
    progress: {
        color: '#4B5563',
    },
});
