import { describe, expect, it } from 'vitest';
import { createRolesPermissionsRoutes } from './routes';

describe('Roles & Permissions route integration', () => {
    it('registers authenticated screens with a permission check for each administrative surface', () => {
        const routes = createRolesPermissionsRoutes('/api/v1/roles-permissions');
        const byPath = new Map(routes.map((route) => [route.path, route]));

        expect(byPath.get('/app/roles')?.meta).toMatchObject({ requiresAuth: true, requiresPermission: 'view-roles' });
        expect(byPath.get('/app/permissions')?.meta).toMatchObject({ requiresAuth: true, requiresPermission: 'view-permissions' });
        expect(byPath.get('/app/users/:user/roles')?.meta).toMatchObject({ requiresAuth: true, requiresPermission: 'manage-user-roles' });
    });

    it('keeps the access-denied screen authenticated without redirecting to itself', () => {
        const route = createRolesPermissionsRoutes('/api/v1/roles-permissions').find((item) => item.path === '/app/access-denied');

        expect(route?.meta).toMatchObject({ requiresAuth: true });
        expect(route?.meta?.requiresPermission).toBeUndefined();
    });
});
