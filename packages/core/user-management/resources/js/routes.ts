import type { RouteRecordRaw } from 'vue-router';
import UserDetailsPage from './pages/UserDetailsPage.vue';
import UserFormPage from './pages/UserFormPage.vue';
import UserManagementLayout from './pages/UserManagementLayout.vue';
import UserListPage from './pages/UserListPage.vue';
import UserTrashPage from './pages/UserTrashPage.vue';
import { configureUserManagementApi } from './api';

/** Compose User Management into the authenticated router supplied by Core Authentication. */
export function createUserManagementRoutes(apiBase: string): RouteRecordRaw[] {
    configureUserManagementApi(apiBase);

    return [{
        path: '/app/users',
        component: UserManagementLayout,
        meta: { requiresAuth: true },
        children: [
            { path: '', component: UserListPage },
            { path: 'create', component: UserFormPage },
            { path: 'trash', component: UserTrashPage },
            { path: ':user/edit', component: UserFormPage },
            { path: ':user', component: UserDetailsPage },
        ],
    }];
}
