import type { RouteRecordRaw } from 'vue-router';
import { createUserManagementRoutes } from '@itassistance-bd/user-management';
import { createRolesPermissionsRoutes } from '@itassistance-bd/roles-permissions';
import { createAuditLogRoutes } from '@itassistance-bd/audit-logs';
import { createModuleManagerRoutes } from '@itassistance-bd/module-manager';
import { createSystemHealthRoutes } from '@itassistance-bd/system-health';
import { createNotificationsRoutes } from '@itassistance-bd/notifications';
import { SettingsApp } from '@itassistance-bd/settings-ui';
import DashboardPage from './DashboardPage.vue';
import CategoriesPage from './CategoriesPage.vue';

/** Register the same protected screens for both host entry points. */
export function createAdminRoutes(mountPoint: HTMLElement, settingsApiBase = '/api/v1/settings'): RouteRecordRaw[] {
    const usersApiBase = mountPoint.dataset.usersApiBase ?? '/api/v1/users';
    const rolesApiBase = mountPoint.dataset.rolesPermissionsApiBase ?? '/api/v1/roles-permissions';
    const auditLogsApiBase = mountPoint.dataset.auditLogsApiBase ?? '/api/v1/audit-logs';
    const modulesApiBase = mountPoint.dataset.modulesApiBase ?? '/api/v1/modules';
    const systemHealthApiBase = mountPoint.dataset.systemHealthApiBase ?? '/api/v1/system-health';
    const notificationsApiBase = mountPoint.dataset.notificationsApiBase ?? '/api/v1/notifications';
    return [
        { path: '/app', component: DashboardPage, meta: { requiresAuth: true } },
        { path: '/app/categories', component: CategoriesPage, meta: { requiresAuth: true, requiresPermission: 'categories.view' } },
        ...createUserManagementRoutes(usersApiBase),
        ...createRolesPermissionsRoutes(rolesApiBase),
        ...createAuditLogRoutes(auditLogsApiBase),
        ...createModuleManagerRoutes(modulesApiBase),
        ...createSystemHealthRoutes(systemHealthApiBase),
        ...createNotificationsRoutes(notificationsApiBase),
        { path: '/admin/settings', component: SettingsApp, props: { apiBase: settingsApiBase }, meta: { requiresAuth: true, requiresPermission: 'view-settings' } },
    ];
}
