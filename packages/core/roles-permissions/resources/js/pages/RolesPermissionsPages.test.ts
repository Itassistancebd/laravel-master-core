import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RolesPage from './RolesPage.vue';
import PermissionsPage from './PermissionsPage.vue';

const api = vi.hoisted(() => ({
    getCurrentPermissions: vi.fn(),
    listRoles: vi.fn(),
    listPermissions: vi.fn(),
    availablePermissions: vi.fn(),
    rolesPermissionsErrorMessage: vi.fn((error: unknown) => error instanceof Error ? error.message : 'Request failed.'),
    rolesPermissionsValidationErrors: vi.fn(() => ({})),
    createRole: vi.fn(),
    updateRole: vi.fn(),
    getRole: vi.fn(),
    syncRolePermissions: vi.fn(),
    deleteRole: vi.fn(),
    bulkDeleteRoles: vi.fn(),
    materializePermission: vi.fn(),
    updatePermission: vi.fn(),
}));

vi.mock('../api', () => api);

const role = { id: 1, name: 'Support', role_key: 'support', guard_name: 'web', is_system: false, permissions_count: 1, members_count: 2, created_at: null };
const permission = { id: 1, name: 'view-users', group_name: 'Users', description: 'View users', guard_name: 'web', is_system: true, is_registered: true, roles_count: 1 };
const pageMeta = { current_page: 1, per_page: 25, total: 1, last_page: 1 };

const DataTableStub = defineComponent({
    props: ['rows', 'error'],
    setup(props, { slots }) {
        return () => h('div', [
            props.error ? h('p', { role: 'alert' }, String(props.error)) : null,
            ...(props.rows as Array<{ id: number; name: string }>).map((row) => h('div', { key: row.id, class: 'test-row' }, [
                h('span', row.name),
                ...(slots.actions?.({ row }) ?? []),
            ])),
        ]);
    },
});

async function mountPage(component: typeof RolesPage | typeof PermissionsPage) {
    const placeholder = { template: '<div />' };
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component }] });
    router.addRoute({ path: '/app/roles', component: placeholder });
    router.addRoute({ path: '/app/permissions', component: placeholder });
    await router.push('/');
    await router.isReady();
    const wrapper = mount(component, { global: { plugins: [router], stubs: { CoreDataTable: DataTableStub } } });
    await flushPromises();

    return wrapper;
}

describe('Roles & Permissions page capabilities', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        api.getCurrentPermissions.mockResolvedValue(['view-roles', 'view-permissions']);
        api.listRoles.mockResolvedValue({ roles: [role], meta: pageMeta });
        api.listPermissions.mockResolvedValue({ permissions: [permission], meta: pageMeta, groups: ['Users'] });
        api.availablePermissions.mockResolvedValue([]);
    });

    it('hides role mutation actions when the user has view access only', async () => {
        api.getCurrentPermissions.mockResolvedValue(['view-roles']);
        const wrapper = await mountPage(RolesPage);

        expect(wrapper.text()).toContain('View');
        expect(wrapper.text()).not.toContain('Create role');
        expect(wrapper.text()).not.toContain('Edit');
        expect(wrapper.text()).not.toContain('Delete');
        expect(api.listPermissions).not.toHaveBeenCalled();
    });

    it('shows role actions only for the corresponding registered abilities', async () => {
        api.getCurrentPermissions.mockResolvedValue(['view-roles', 'view-permissions', 'create-roles', 'edit-roles', 'delete-roles']);
        const wrapper = await mountPage(RolesPage);

        expect(wrapper.text()).toContain('Create role');
        expect(wrapper.text()).toContain('Edit');
        expect(wrapper.text()).toContain('Delete');
    });

    it('loads saved permissions and submits a selected grant through the supported role API', async () => {
        api.getCurrentPermissions.mockResolvedValue(['view-roles', 'edit-roles', 'view-permissions']);
        api.getRole.mockResolvedValue({ ...role, permission_ids: [] });
        api.updateRole.mockResolvedValue(role);
        api.syncRolePermissions.mockResolvedValue(undefined);
        const wrapper = await mountPage(RolesPage);

        await wrapper.findAll('button').find((button) => button.text() === 'Edit')!.trigger('click');
        await flushPromises();
        const checkbox = wrapper.get('input[type="checkbox"]');
        await checkbox.setValue(true);
        await wrapper.findAll('form')[0].trigger('submit');
        await flushPromises();

        expect(api.getRole).toHaveBeenCalledWith(role.id);
        expect(api.syncRolePermissions).toHaveBeenCalledWith(role.id, [permission.id]);
        expect(wrapper.text()).toContain('Role updated.');
    });

    it('shows a system role as view-only even when the operator can edit ordinary roles', async () => {
        api.getCurrentPermissions.mockResolvedValue(['view-roles', 'edit-roles', 'view-permissions']);
        const systemRole = { ...role, name: 'Core Super Admin', role_key: 'core-super-admin', is_system: true };
        api.listRoles.mockResolvedValue({ roles: [systemRole], meta: pageMeta });
        api.getRole.mockResolvedValue({ ...systemRole, permission_ids: [permission.id] });
        const wrapper = await mountPage(RolesPage);

        expect(wrapper.findAll('button').some((button) => button.text() === 'Edit')).toBe(false);
        await wrapper.findAll('button').find((button) => button.text() === 'View')!.trigger('click');
        await flushPromises();

        const checkbox = wrapper.get('input[type="checkbox"]');
        expect((checkbox.element as HTMLInputElement).checked).toBe(true);
        expect((checkbox.element as HTMLInputElement).disabled).toBe(true);
    });

    it('loads the role response envelope with an omitted blank search and preserves typed search', async () => {
        const wrapper = await mountPage(RolesPage);
        expect(wrapper.findAll('.test-row').map((row) => row.text())[0]).toContain('Support');
        expect(api.listRoles).toHaveBeenCalledWith(expect.objectContaining({ search: '' }));

        await wrapper.get('#role-search').setValue('Support');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(api.listRoles).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'Support' }));
    });

    it('renders a backend 403 response as an authorization error instead of exposing actions', async () => {
        api.getCurrentPermissions.mockResolvedValue(['view-roles']);
        api.listRoles.mockRejectedValue(new Error('Forbidden: view-roles was revoked.'));
        const wrapper = await mountPage(RolesPage);

        expect(wrapper.get('[role="alert"]').text()).toContain('Forbidden');
        expect(wrapper.text()).not.toContain('Create role');
        expect(wrapper.text()).not.toContain('Edit');
    });

    it('hides permission catalog mutations without manage-permissions', async () => {
        api.getCurrentPermissions.mockResolvedValue(['view-permissions']);
        const wrapper = await mountPage(PermissionsPage);

        expect(wrapper.text()).toContain('view-users');
        expect(wrapper.text()).not.toContain('Add to permission catalog');
        expect(wrapper.text()).not.toContain('Edit details');
        expect(api.availablePermissions).not.toHaveBeenCalled();
        expect(wrapper.text()).not.toContain('No unmaterialized registered abilities remain.');
    });

    it('loads the permission data envelope and applies the selected group filter', async () => {
        const wrapper = await mountPage(PermissionsPage);
        expect(wrapper.findAll('.test-row').map((row) => row.text())).toEqual(['view-users']);
        expect(api.listPermissions).toHaveBeenCalledWith(expect.objectContaining({ search: '', group: undefined }));

        await wrapper.get('select').setValue('Users');
        await flushPromises();
        expect(api.listPermissions).toHaveBeenLastCalledWith(expect.objectContaining({ group: 'Users' }));
    });

    it('shows permission catalog actions to a manager and renders API 403 failures', async () => {
        api.getCurrentPermissions.mockResolvedValue(['view-permissions', 'manage-permissions']);
        const manager = await mountPage(PermissionsPage);
        expect(manager.text()).toContain('Add to permission catalog');
        expect(manager.text()).toContain('Edit details');
        manager.unmount();

        api.listPermissions.mockRejectedValue(new Error('Forbidden: permission catalog access was revoked.'));
        const wrapper = await mountPage(PermissionsPage);
        expect(wrapper.get('[role="alert"]').text()).toContain('Forbidden');
    });

    it('renders registered code abilities in the manager selector separately from database catalog rows', async () => {
        api.getCurrentPermissions.mockResolvedValue(['view-permissions', 'manage-permissions']);
        api.availablePermissions.mockResolvedValue([
            { name: 'edit-users', group_name: 'Users', description: 'Edit users' },
            { name: 'view-roles', group_name: 'Roles', description: 'View roles' },
        ]);
        const wrapper = await mountPage(PermissionsPage);

        const options = wrapper.findAll('select option').map((option) => option.text());
        expect(options).toContain('edit-users — Users');
        expect(options).toContain('view-roles — Roles');
        expect(wrapper.findAll('.test-row').map((row) => row.text())[0]).toContain('view-users');
        expect(api.materializePermission).not.toHaveBeenCalled();
    });

    it('materializes only the selected registered ability and reloads the database catalog after success', async () => {
        api.getCurrentPermissions.mockResolvedValue(['view-permissions', 'manage-permissions']);
        api.availablePermissions.mockResolvedValue([{ name: 'view-users', group_name: 'Users', description: 'View users' }]);
        api.listPermissions
            .mockResolvedValueOnce({ permissions: [], meta: pageMeta, groups: [] })
            .mockResolvedValueOnce({ permissions: [permission], meta: pageMeta, groups: ['Users'] });
        api.materializePermission.mockResolvedValue(permission);
        const wrapper = await mountPage(PermissionsPage);

        await wrapper.findAll('button').find((button) => button.text() === 'Add to permission catalog')!.trigger('click');
        await flushPromises();

        expect(api.materializePermission).toHaveBeenCalledWith('view-users');
        expect(api.materializePermission).toHaveBeenCalledTimes(1);
        expect(api.listPermissions).toHaveBeenCalledTimes(2);
        expect(wrapper.findAll('.test-row').map((row) => row.text())[0]).toContain('view-users');
    });
});
