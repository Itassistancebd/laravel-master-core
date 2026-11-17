export interface PageMeta {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
}

export interface RoleRecord {
    [key: string]: unknown;
    id: number;
    name: string;
    role_key: string;
    guard_name: string;
    is_system: boolean;
    permissions_count: number;
    members_count: number;
    created_at: string | null;
    permission_ids?: number[];
}

export interface PermissionRecord {
    [key: string]: unknown;
    id: number;
    name: string;
    group_name: string;
    description: string | null;
    guard_name: string;
    is_system: boolean;
    is_registered: boolean;
    roles_count: number;
}

export interface RegisteredPermission {
    name: string;
    group_name: string;
    description: string;
}

export interface ApiEnvelope<T> {
    success: boolean;
    data: T;
    message?: string;
    meta?: PageMeta;
    errors?: Record<string, string[]>;
    groups?: string[];
}

export interface RolePage {
    roles: RoleRecord[];
    meta: PageMeta;
}

export interface PermissionPage {
    permissions: PermissionRecord[];
    meta: PageMeta;
    groups: string[];
}

export interface AssignedUserRoles {
    user: { id: number | string; name: string | null; email: string };
    role_ids: number[];
    roles: Array<Pick<RoleRecord, 'id' | 'name' | 'role_key'>>;
    effective_permissions: string[];
}
