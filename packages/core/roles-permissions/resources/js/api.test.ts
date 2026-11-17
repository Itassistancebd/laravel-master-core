import { beforeEach, describe, expect, it, vi } from 'vitest';

const client = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    interceptors: { response: { use: vi.fn() } },
}));

vi.mock('axios', () => ({
    default: { create: vi.fn(() => client), isAxiosError: vi.fn(() => false) },
}));

import { availablePermissions, listPermissions, listRoles } from './api';

const meta = { current_page: 1, per_page: 25, total: 1, last_page: 1 };

describe('roles and permissions API response parsing and query serialization', () => {
    beforeEach(() => vi.clearAllMocks());

    it('omits blank role search and parses the role data/meta envelope', async () => {
        const roles = [{ id: 1, name: 'Support', role_key: 'support', guard_name: 'web', is_system: false, permissions_count: 0, members_count: 2, created_at: null }];
        client.get.mockResolvedValue({ data: { success: true, data: roles, meta } });

        await expect(listRoles({ search: '', page: 1, per_page: 25 })).resolves.toEqual({ roles, meta });
        expect(client.get).toHaveBeenCalledWith('/roles', { params: { page: 1, per_page: 25 } });
    });

    it('preserves non-empty role search and parses filtered permission rows and groups', async () => {
        const permissions = [{ id: 1, name: 'view-users', group_name: 'Users', description: 'View users', guard_name: 'web', is_system: true, is_registered: true, roles_count: 0 }];
        client.get.mockResolvedValue({ data: { success: true, data: permissions, meta, groups: ['Users'] } });

        const result = await listPermissions({ search: 'users', group: 'Users', page: 1 });

        expect(result).toEqual({ permissions, meta, groups: ['Users'] });
        expect(client.get).toHaveBeenCalledWith('/permissions', { params: { search: 'users', group: 'Users', page: 1 } });
    });

    it('parses registered code abilities from the available endpoint data envelope', async () => {
        const registered = [{ name: 'view-users', group_name: 'Users', description: 'View users' }];
        client.get.mockResolvedValue({ data: { success: true, data: registered } });

        await expect(availablePermissions()).resolves.toEqual(registered);
        expect(client.get).toHaveBeenCalledWith('/permissions/available');
    });
});
