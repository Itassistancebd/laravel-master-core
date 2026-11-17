import type { RouteRecordRaw } from 'vue-router';
import { configureAuditLogsApi } from './api';
import AuditLogsPage from './pages/AuditLogsPage.vue';

/** Adds only a permission-protected, read-only route to the host Auth application shell. */
export function createAuditLogRoutes(apiBase: string): RouteRecordRaw[] {
    configureAuditLogsApi(apiBase);
    return [{ path: '/app/audit-logs', component: AuditLogsPage, meta: { requiresAuth: true, requiresPermission: 'view-audit-logs' } }];
}
