import { createRouter, createWebHistory } from 'vue-router';
import type { RouteRecordRaw } from 'vue-router';
import AuthPage from './pages/AuthPage.vue';
import { configureAuth, loadAuthenticatedUser } from './state';

declare module 'vue-router' {
    interface RouteMeta {
        requiresAuth?: boolean;
        requiresPermission?: string;
        guest?: boolean;
    }
}

export type PermissionRouteCheck = (permission: string) => Promise<boolean>;

export function createAuthRouter(
    apiBase: string,
    extensionRoutes: RouteRecordRaw[] = [],
    canAccessPermission?: PermissionRouteCheck,
) {
    configureAuth(apiBase);

    const guestPage = { component: AuthPage, meta: { guest: true } };
    const protectedPage = { component: AuthPage, meta: { requiresAuth: true } };
    const router = createRouter({
        history: createWebHistory(),
        routes: [
            ...extensionRoutes,
            { path: '/login', ...guestPage },
            { path: '/forgot-password', ...guestPage },
            { path: '/reset-password/:token', ...guestPage },
            { path: '/email/verify', ...protectedPage },
            { path: '/user/confirm-password', ...protectedPage },
            { path: '/app/:pathMatch(.*)*', ...protectedPage },
            { path: '/:pathMatch(.*)*', redirect: '/login' },
        ],
    });

    router.beforeEach(async (to) => {
        if (to.meta.requiresAuth && !await loadAuthenticatedUser()) {
            return { path: '/login', query: { redirect: to.fullPath } };
        }

        const requiredPermission = to.meta.requiresPermission;
        // This browser check keeps navigation clear; each API endpoint still enforces Laravel authorization.
        if (typeof requiredPermission === 'string'
            && (!canAccessPermission || !await canAccessPermission(requiredPermission))) {
            return '/app/access-denied';
        }

        if (to.meta.guest && await loadAuthenticatedUser()) return '/app';

        return true;
    });

    return router;
}
