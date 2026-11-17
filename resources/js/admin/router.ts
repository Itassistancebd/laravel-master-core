import { createAuthRouter } from '@itassistance-bd/auth';
import { canAccessPermission } from '@itassistance-bd/roles-permissions';
import { createAdminRoutes } from './routes';

export function createAdminAuthRouter(mountPoint: HTMLElement, settingsApiBase?: string, authApiBase?: string) {
    return createAuthRouter(
        authApiBase ?? mountPoint.dataset.apiBase ?? '/api/v1/auth',
        createAdminRoutes(mountPoint, settingsApiBase),
        canAccessPermission,
    );
}
