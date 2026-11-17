import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserListPage from './UserListPage.vue';

const api = vi.hoisted(() => ({
    getCapabilities: vi.fn(),
    listUsers: vi.fn(),
    exportUsers: vi.fn(),
    bulkUsers: vi.fn(),
    trashUser: vi.fn(),
    updateUserStatus: vi.fn(),
    userApiErrorMessage: vi.fn((error: unknown) => error instanceof Error ? error.message : 'Request failed.'),
}));

vi.mock('../api', () => api);

const meta = { current_page: 1, per_page: 25, total: 0, last_page: 1, from: null, to: null };
const capabilities = {
    view: true, create: true, update: true, status: true, delete: true, restore: true,
    force_delete: true, password_reset: true, verification: true, manage: false, export: true, import: true,
};

async function mountPage() {
    const placeholder = { template: '<div />' };
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', component: UserListPage },
            { path: '/app/users/create', component: placeholder },
            { path: '/app/users/:user/edit', component: placeholder },
            { path: '/app/users/:user', component: placeholder },
        ],
    });
    await router.push('/');
    await router.isReady();
    const wrapper = mount(UserListPage, { global: { plugins: [router] } });
    await flushPromises();
    return wrapper;
}

describe('UserListPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        api.getCapabilities.mockResolvedValue(capabilities);
        api.listUsers.mockResolvedValue({ users: [], meta });
        api.exportUsers.mockResolvedValue(new Blob(['users'], { type: 'text/csv' }));
    });

    it('sends search input to the server and renders the shared table', async () => {
        const wrapper = await mountPage();
        await wrapper.get('input[placeholder="Name or email"]').setValue('Ada');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(api.listUsers).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'Ada', page: 1, per_page: 25 }), false, expect.any(AbortSignal));
        expect(wrapper.text()).toContain('Users');
        expect(wrapper.text()).toContain('No records match your search.');
    });

    it('shows API authorization failures in the table error state', async () => {
        api.listUsers.mockRejectedValueOnce(new Error('Forbidden'));
        const wrapper = await mountPage();

        expect(wrapper.get('[role="alert"]').text()).toContain('Forbidden');
        expect(wrapper.get('[role="alert"] button').text()).toBe('Retry');
    });

    it('debounces search and applies status and verification filters together immediately', async () => {
        const wrapper = await mountPage();
        api.listUsers.mockClear();
        vi.useFakeTimers();
        try {
            await wrapper.get('input[placeholder="Name or email"]').setValue('Ada');
            await vi.advanceTimersByTimeAsync(349);
            expect(api.listUsers).not.toHaveBeenCalled();
            await vi.advanceTimersByTimeAsync(1);
            await flushPromises();
            expect(api.listUsers).toHaveBeenCalledTimes(1);
            expect(api.listUsers).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'Ada', status: undefined, email_verified: undefined, page: 1 } ), false, expect.any(AbortSignal));

            api.listUsers.mockClear();
            await wrapper.get('select').setValue('active');
            await wrapper.findAll('select')[1].setValue('verified');
            await flushPromises();
            expect(api.listUsers).toHaveBeenCalledTimes(2);
            expect(api.listUsers.mock.calls[0][0]).toEqual(expect.objectContaining({ search: 'Ada', status: 'active', email_verified: undefined }));
            expect(api.listUsers).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'Ada', status: 'active', email_verified: 'verified', page: 1 }), false, expect.any(AbortSignal));
        } finally {
            vi.useRealTimers();
        }
    });

    it('sorts every sortable user column and carries sorting through pagination', async () => {
        api.listUsers.mockResolvedValue({
            users: [{ id: 7, name: 'Ada', email: 'ada@example.test', email_verified_at: null, is_active: true, created_at: null, deleted_at: null }],
            meta: { ...meta, total: 30, last_page: 2 },
        });
        const wrapper = await mountPage();
        api.listUsers.mockClear();
        const sortButtons = wrapper.findAll('.mc-table__sort');
        expect(sortButtons).toHaveLength(5);

        for (const [index, key] of ['name', 'email', 'email_verified_at', 'is_active', 'created_at'].entries()) {
            await sortButtons[index].trigger('click');
            await flushPromises();
            expect(api.listUsers).toHaveBeenLastCalledWith(expect.objectContaining({ sort_by: key }), false, expect.any(AbortSignal));
        }

        await wrapper.get('button[aria-label="Page 2"]').trigger('click');
        await flushPromises();
        expect(api.listUsers).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2, sort_by: 'created_at', sort_direction: 'asc' }), false, expect.any(AbortSignal));
    });

    it('ignores an older search response after a newer one completes', async () => {
        const wrapper = await mountPage();
        api.listUsers.mockClear();
        let resolveOld!: (value: { users: Array<{ id: number; name: string; email: string; email_verified_at: null; is_active: boolean; created_at: null; deleted_at: null }>; meta: typeof meta }) => void;
        let resolveNew!: typeof resolveOld;
        api.listUsers
            .mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve as typeof resolveOld; }))
            .mockImplementationOnce(() => new Promise((resolve) => { resolveNew = resolve as typeof resolveNew; }));
        vi.useFakeTimers();
        try {
            await wrapper.get('input[placeholder="Name or email"]').setValue('Old');
            await vi.advanceTimersByTimeAsync(350);
            await wrapper.get('input[placeholder="Name or email"]').setValue('New');
            await vi.advanceTimersByTimeAsync(350);
            const result = (name: string) => ({
                users: [{ id: name === 'Old' ? 1 : 2, name, email: `${name.toLowerCase()}@example.test`, email_verified_at: null, is_active: true, created_at: null, deleted_at: null }],
                meta,
            });
            resolveNew(result('New'));
            await flushPromises();
            resolveOld(result('Old'));
            await flushPromises();
            expect(wrapper.text()).toContain('New');
            expect(wrapper.text()).not.toContain('Old');
        } finally {
            vi.useRealTimers();
        }
    });

    it('renders labeled icon actions and confirms moving a user to trash', async () => {
        api.listUsers.mockResolvedValue({ users: [{ id: 3, name: 'Sam', email: 'sam@example.test', email_verified_at: null, is_active: true, created_at: null, deleted_at: null }], meta: { ...meta, total: 1 } });
        const wrapper = await mountPage();
        expect(wrapper.get('a[aria-label="View Sam"]').attributes('title')).toBe('View');
        expect(wrapper.get('a[aria-label="Edit Sam"]').attributes('title')).toBe('Edit');
        await wrapper.get('button[aria-label="Move Sam to trash"]').trigger('click');
        expect(document.body.textContent).toContain('Are you sure you want to move to trash this user?');
    });

    it('exports every active filter through the server export endpoint', async () => {
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
        Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:test') });
        Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });
        const wrapper = await mountPage();
        await wrapper.findAll('select')[0].setValue('active');
        await wrapper.findAll('select')[1].setValue('verified');
        await flushPromises();
        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(api.exportUsers).toHaveBeenCalledWith(expect.objectContaining({ status: 'active', email_verified: 'verified', sort_by: 'created_at', sort_direction: 'desc' }));
        expect(wrapper.text()).toContain('Import Users');
        expect(wrapper.text()).toContain('Filtered users CSV downloaded.');
    });

    it('keeps import and export visible but disabled when the actor lacks their exact permissions', async () => {
        api.getCapabilities.mockResolvedValue({ ...capabilities, export: false, import: false });
        const wrapper = await mountPage();

        const exportButton = wrapper.findAll('button').find((button) => button.text() === 'Export Users');
        const importButton = wrapper.findAll('button').find((button) => button.text() === 'Import Users');
        expect(exportButton).toBeDefined();
        expect(importButton).toBeDefined();
        expect((exportButton!.element as HTMLButtonElement).disabled).toBe(true);
        expect((importButton!.element as HTMLButtonElement).disabled).toBe(true);
        expect(wrapper.text()).toContain('export-users');
        expect(wrapper.text()).toContain('import-users');
        expect(wrapper.text()).toContain('create-users');

        await exportButton!.trigger('click');
        await importButton!.trigger('click');
        expect(api.exportUsers).not.toHaveBeenCalled();
        expect(document.body.querySelector('#mc-user-import-title')).toBeNull();
    });
});
