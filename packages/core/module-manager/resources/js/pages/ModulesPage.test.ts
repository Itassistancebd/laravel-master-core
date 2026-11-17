import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import ModulesPage from './ModulesPage.vue';
import { createModuleManagerRoutes } from '../routes';

const api = vi.hoisted(() => ({ configureModuleManagerApi: vi.fn(), listModules: vi.fn(), getModule: vi.fn() }));
vi.mock('../api', () => ({
    ...api,
    moduleApiError: (error: { status?: number }) => ({
        status: error.status,
        message: error.status === 401 ? 'Session expired.' : 'Request failed.',
    }),
}));

const moduleRecord = {
    key: 'core-foundation', name: 'Foundation', description: 'Core health and configuration.',
    composer_package: 'example/laravel-master-core-foundation',
    installation: { installed: true, version: 'dev-main' },
    dependencies: [{ package_name: 'laravel/framework', installed: true }],
    provider_loaded: true, readiness_status: 'runtime_not_verified', documentation: 'packages/core/foundation/README.md',
};

describe('Module Registry page', () => {
    it('registers an authenticated read-only route with the required permission', () => {
        const route = createModuleManagerRoutes('/api/v1/modules')[0];
        expect(route.meta).toMatchObject({ requiresAuth: true, requiresPermission: 'view-modules' });
        expect(api.configureModuleManagerApi).toHaveBeenCalledWith('/api/v1/modules');
    });

    it('lists modules and provides read-only detail access', async () => {
        api.listModules.mockResolvedValue({ modules: [moduleRecord], meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 } });
        api.getModule.mockResolvedValue(moduleRecord);
        const wrapper = mount(ModulesPage, {
            global: {
                stubs: {
                    CoreDataTable: {
                        props: ['rows', 'columns'],
                        template: '<div><span v-for="row in rows" :key="row.key">{{ row.name }} {{ row.readiness_status }}</span><slot name="actions" :row="rows[0]" /></div>',
                    },
                },
            },
        });
        await flushPromises();

        expect(wrapper.text()).toContain('Foundation');
        expect(wrapper.text()).toContain('Runtime not verified');
        const viewButton = wrapper.findAll('button').find((button) => button.text() === 'View');
        expect(viewButton).toBeDefined();
        await viewButton!.trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Declared dependencies');
        expect(wrapper.text()).toContain('laravel/framework');
        expect(wrapper.findAll('button').map((button) => button.text()).join(' ')).not.toMatch(/Install|Update|Disable|Enable|Migrate|Delete/);
    });

    it('renders forbidden and expired-session failures without mutation controls', async () => {
        api.listModules.mockRejectedValue({ status: 403 });
        const forbidden = mount(ModulesPage);
        await flushPromises();
        expect(forbidden.get('[role="alert"]').text()).toContain('do not have permission');

        api.listModules.mockRejectedValue({ status: 401 });
        const expired = mount(ModulesPage);
        await flushPromises();
        expect(expired.get('[role="alert"]').text()).toContain('Session expired.');
        expect(expired.findAll('button').map((button) => button.text()).join(' ')).not.toMatch(/Install|Update|Disable|Enable|Migrate|Delete/);
    });
});
