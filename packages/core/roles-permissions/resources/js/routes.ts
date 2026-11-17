import type { RouteRecordRaw } from 'vue-router';
import RolesPage from './pages/RolesPage.vue';
import PermissionsPage from './pages/PermissionsPage.vue';
import UserRolesPage from './pages/UserRolesPage.vue';
import AccessDeniedPage from './pages/AccessDeniedPage.vue';
import { configureRolesPermissionsApi } from './api';

/** Compose the package screens into the host Auth SPA and share its session. */
export function createRolesPermissionsRoutes(apiBase: string): RouteRecordRaw[] {
    configureRolesPermissionsApi(apiBase);

    return [
        { path: '/app/roles', component: RolesPage, meta: { requiresAuth: true, requiresPermission: 'view-roles' } },
        { path: '/app/permissions', component: PermissionsPage, meta: { requiresAuth: true, requiresPermission: 'view-permissions' } },
        { path: '/app/users/:user/roles', component: UserRolesPage, meta: { requiresAuth: true, requiresPermission: 'manage-user-roles' } },
        { path: '/app/access-denied', component: AccessDeniedPage, meta: { requiresAuth: true } },
    ];
}
