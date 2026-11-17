import type { RouteRecordRaw } from 'vue-router';
import NotificationCenterPage from './pages/NotificationCenterPage.vue';
import { configureNotificationsApi } from './api';

/** Add the recipient-owned inbox to the existing authenticated Auth SPA. */
export function createNotificationsRoutes(apiBase: string): RouteRecordRaw[] {
    configureNotificationsApi(apiBase);

    return [{
        path: '/app/notifications',
        component: NotificationCenterPage,
        meta: { requiresAuth: true },
    }];
}
