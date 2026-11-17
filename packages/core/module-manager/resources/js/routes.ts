import type { RouteRecordRaw } from 'vue-router';
import { configureModuleManagerApi } from './api';
import ModulesPage from './pages/ModulesPage.vue';

/** Adds a read-only, Gate-protected inventory page to the host Auth application. */
export function createModuleManagerRoutes(apiBase: string): RouteRecordRaw[] {
    configureModuleManagerApi(apiBase);

    return [{
        path: '/app/modules',
        component: ModulesPage,
        meta: { requiresAuth: true, requiresPermission: 'view-modules' },
    }];
}
