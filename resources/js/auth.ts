import './bootstrap';
import axios from 'axios';
import { createApp } from 'vue';
import AdminApp from './admin/AdminApp.vue';
import { createAdminAuthRouter } from './admin/router';
import '@itassistance-bd/ui/styles.css';
// Keep production styles explicit for each selected feature package; Vite loads SFC styles in development.
import '@itassistance-bd/auth/style.css';
import '@itassistance-bd/user-management/style.css';
import '@itassistance-bd/roles-permissions/style.css';
import '@itassistance-bd/audit-logs/style.css';
import '@itassistance-bd/module-manager/style.css';
import '@itassistance-bd/system-health/style.css';
import '@itassistance-bd/notifications/style.css';
import '@itassistance-bd/settings-ui/style.css';
import '../css/admin.css';

const mountPoint = document.querySelector<HTMLElement>('#master-core-auth');

if (mountPoint) {
    // Fortify and Sanctum share Laravel's session and CSRF protection for the SPA.
    axios.defaults.withCredentials = true;
    axios.defaults.withXSRFToken = true;
    axios.defaults.headers.common.Accept = 'application/json';
    const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

    if (csrfToken) axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;

    const page = mountPoint.dataset.page ?? 'application';
    if (page === 'application' && !window.location.pathname.startsWith('/app')) {
        window.history.replaceState({}, '', '/app');
    }

    createApp(AdminApp, {
        resetToken: mountPoint.dataset.token ?? '',
        resetEmail: mountPoint.dataset.email ?? '',
    }).use(createAdminAuthRouter(mountPoint)).mount(mountPoint);
}
