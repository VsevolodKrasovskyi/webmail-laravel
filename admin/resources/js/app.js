import '../css/app.css';
import 'vue-sonner/style.css';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

// Lazy: each page (and what only it imports, e.g. the Tiptap editor on Compose)
// becomes its own chunk, loaded when that page is first visited.
const pages = import.meta.glob('./Pages/**/*.vue');

createInertiaApp({
    // Pages set their own title with <Head title="..." />.
    title: (title) => (title ? `${title} · Mail Admin` : 'Mail Admin'),
    resolve: (name) => {
        const page = pages[`./Pages/${name}.vue`];

        if (! page) {
            throw new Error(`Unknown page: ${name}`);
        }

        return page();
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
