import axios from 'axios';
import type {
    ApiEnvelope,
    AssignedUserRoles,
    PageMeta,
    PermissionPage,
    PermissionRecord,
    RegisteredPermission,
    RolePage,
    RoleRecord,
} from './types';

function createClient(apiBase: string) {
    const client = axios.create({ baseURL: apiBase, withCredentials: true, withXSRFToken: true });
    client.interceptors.response.use((response) => response, (error: unknown) => {
        if (axios.isAxiosError(error) && error.response?.status === 401 && typeof window !== 'undefined') {
            const returnPath = `${window.location.pathname}${window.location.search}`;
            window.location.assign(`/login?redirect=${encodeURIComponent(returnPath)}`);
        }

        return Promise.reject(error);
    });

    return client;
}

let api = createClient('/api/v1/roles-permissions');

export function withoutBlankQueryValues(params: Record<string, string | number | undefined>): Record<string, string | number> {
    return Object.fromEntries(Object.entries(params).filter(([, value]) =>
        value !== undefined && !(typeof value === 'string' && value.trim() === ''),
    )) as Record<string, string | number>;
}

export function configureRolesPermissionsApi(apiBase: string): void {
    api = createClient(apiBase);
}

export async function getCurrentPermissions(): Promise<string[]> {
    // Query current Laravel Gate decisions on navigation; do not persist per-user grants in the browser.
    const response = await api.get<ApiEnvelope<{ permissions: string[] }>>('/permissions/me');
    return response.data.data.permissions;
}

/** Route metadata only controls navigation; backend Gate checks remain authoritative. */
export async function canAccessPermission(permission: string): Promise<boolean> {
    try {
        return (await getCurrentPermissions()).includes(permission);
    } catch {
        return false;
    }
}

export async function refreshCurrentPermissions(): Promise<void> {
    // Recheck the current Gate results after a role change instead of retaining decisions between requests.
    await getCurrentPermissions();
}

export async function listRoles(params: Record<string, string | number | undefined>): Promise<RolePage> {
    const response = await api.get<ApiEnvelope<RoleRecord[]>>('/roles', { params: withoutBlankQueryValues(params) });
    return { roles: response.data.data, meta: response.data.meta as PageMeta };
}

export async function getRole(id: number): Promise<RoleRecord> {
    const response = await api.get<ApiEnvelope<RoleRecord>>(`/roles/${id}`);
    return response.data.data;
}

export async function createRole(name: string): Promise<RoleRecord> {
    const response = await api.post<ApiEnvelope<RoleRecord>>('/roles', { name });
    return response.data.data;
}

export async function updateRole(id: number, name: string): Promise<RoleRecord> {
    const response = await api.put<ApiEnvelope<RoleRecord>>(`/roles/${id}`, { name });
    return response.data.data;
}

export async function deleteRole(id: number): Promise<void> {
    await api.delete(`/roles/${id}`);
}

export async function bulkDeleteRoles(roleIds: number[]): Promise<number> {
    const response = await api.post<ApiEnvelope<{ deleted: number }>>('/roles/bulk-delete', { role_ids: roleIds });
    return response.data.data.deleted;
}

export async function syncRolePermissions(id: number, permissionIds: number[]): Promise<void> {
    await api.put(`/roles/${id}/permissions`, { permission_ids: permissionIds });
    await refreshCurrentPermissions();
}

export async function listPermissions(params: Record<string, string | number | undefined>): Promise<PermissionPage> {
    const response = await api.get<ApiEnvelope<PermissionRecord[]>>('/permissions', { params: withoutBlankQueryValues(params) });
    return { permissions: response.data.data, meta: response.data.meta as PageMeta, groups: response.data.groups ?? [] };
}

export async function availablePermissions(): Promise<RegisteredPermission[]> {
    const response = await api.get<ApiEnvelope<RegisteredPermission[]>>('/permissions/available');
    return response.data.data;
}

export async function materializePermission(name: string): Promise<PermissionRecord> {
    const response = await api.post<ApiEnvelope<PermissionRecord>>('/permissions', { name });
    return response.data.data;
}

export async function updatePermission(id: number, groupName: string, description: string): Promise<PermissionRecord> {
    const response = await api.put<ApiEnvelope<PermissionRecord>>(`/permissions/${id}`, {
        group_name: groupName,
        description: description || null,
    });
    return response.data.data;
}

export async function deletePermission(id: number): Promise<void> {
    await api.delete(`/permissions/${id}`);
}

export async function getUserRoles(userId: number | string): Promise<AssignedUserRoles> {
    const response = await api.get<ApiEnvelope<AssignedUserRoles>>(`/users/${encodeURIComponent(userId)}/roles`);
    return response.data.data;
}

export async function syncUserRoles(userId: number | string, roleIds: number[]): Promise<number[]> {
    const response = await api.put<ApiEnvelope<{ role_ids: number[] }>>(`/users/${encodeURIComponent(userId)}/roles`, { role_ids: roleIds });
    await refreshCurrentPermissions();
    return response.data.data.role_ids;
}

export function rolesPermissionsErrorMessage(error: unknown): string {
    if (axios.isAxiosError<{ message?: string }>(error)) {
        return error.response?.data?.message ?? 'We could not complete that request. Please try again.';
    }

    return 'We could not complete that request. Please try again.';
}

export function rolesPermissionsValidationErrors(error: unknown): Record<string, string[]> {
    if (axios.isAxiosError<{ errors?: Record<string, string[]> }>(error)) {
        return error.response?.data?.errors ?? {};
    }

    return {};
}
