import type { RouteRecordRaw } from 'vue-router';
import SystemHealthPage from './pages/SystemHealthPage.vue';
import { configureSystemHealthApi } from './api';

/** Adds the protected, read-only readiness page to the existing Auth SPA. */
export function createSystemHealthRoutes(apiBase: string): RouteRecordRaw[] {
    configureSystemHealthApi(apiBase);

    return [{
        path: '/app/system-health',
        component: SystemHealthPage,
        meta: { requiresAuth: true, requiresPermission: 'view-system-health' },
    }];
}
