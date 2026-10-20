import '../css/app.css';
import 'vue-sonner/style.css';
import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';

const pageTitles = {
    'Dashboard': 'Dashboard',
    'Domains/Index': 'Domains',
    'Mailboxes/Index': 'Mailboxes',
    'Aliases/Index': 'Aliases',
    'Settings': 'Settings',
    'Webmail': 'Webmail',
    'Auth/Login': 'Sign in',
};

function setTitle(component) {
    const label = pageTitles[component];
    document.title = label ? `${label} · Mail Admin` : 'Mail Admin';
}

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        setTitle(props.initialPage.component);
        router.on('navigate', (event) => setTitle(event.detail.page.component));

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
