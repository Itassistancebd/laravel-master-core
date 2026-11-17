import './bootstrap';
import axios from 'axios';
import { createApp } from 'vue';
import AdminApp from './admin/AdminApp.vue';
import { createAdminAuthRouter } from './admin/router';
import '@itassistance-bd/ui/styles.css';
import '@itassistance-bd/auth/style.css';
import '@itassistance-bd/user-management/style.css';
import '@itassistance-bd/roles-permissions/style.css';
import '@itassistance-bd/audit-logs/style.css';
import '@itassistance-bd/module-manager/style.css';
import '@itassistance-bd/system-health/style.css';
import '@itassistance-bd/notifications/style.css';
import '@itassistance-bd/settings-ui/style.css';
import '../css/admin.css';

const mountPoint = document.querySelector<HTMLElement>('#master-core-settings');

if (mountPoint) {
    // Reuse the authenticated shell and session guard for Settings and all admin links.
    axios.defaults.withCredentials = true;
    axios.defaults.withXSRFToken = true;
    axios.defaults.headers.common.Accept = 'application/json';
    const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

    if (csrfToken) {
        axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
    }

    createApp(AdminApp).use(createAdminAuthRouter(
        mountPoint,
        mountPoint.dataset.apiBase ?? '/api/v1/settings',
        mountPoint.dataset.authApiBase ?? '/api/v1/auth',
    )).mount(mountPoint);
}
