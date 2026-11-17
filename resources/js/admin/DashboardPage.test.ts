import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { get, axiosMock } = vi.hoisted(() => {
    const get = vi.fn();
    return {
        get,
        axiosMock: {
            get,
            create: vi.fn(() => ({ get, interceptors: { response: { use: vi.fn() } } })),
            isAxiosError: vi.fn(() => false),
        },
    };
});
vi.mock('axios', () => ({ default: axiosMock }));

import DashboardPage from './DashboardPage.vue';

const dashboard = {
    generated_at: '2026-11-16T10:00:00+00:00',
    current_user: { name: 'Ari Example' },
    access: { users: true, roles: true, permissions: true, audit: true, modules: true, health: true, create_users: true, manage_roles: true },
    metrics: { users: 12, active_users: 10, inactive_users: 2, roles: 3, permissions: 18, modules: 7 },
    user_growth: [{ label: 'Jun', count: 0 }, { label: 'Jul', count: 2 }],
    recent_activity: [{ id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', event_name: 'user.created', category: 'administration', outcome: 'successful', actor: { type: 'User', id: '7' }, occurred_at: '2026-11-16T09:00:00+00:00' }],
    health: { overall_status: 'healthy', checks: [{ name: 'Primary database', status: 'healthy' }] },
    module_readiness: { runtime_not_verified: 7 },
};

function mountPage() {
    return mount(DashboardPage, { global: { stubs: {
        RouterLink: { template: '<a :href="to"><slot /></a>', props: ['to'] },
        DashboardUsersTable: { template: '<section class="dashboard-users-table"><a href="/app/users/create">Create user</a></section>' },
    } } });
}

describe('DashboardPage', () => {
    beforeEach(() => get.mockReset());

    it('renders real summary data, activity, health and authorized shortcuts', async () => {
        get.mockResolvedValue({ data: { data: dashboard } });
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.text()).toContain('Ari Example');
        expect(wrapper.text()).toContain('12');
        expect(wrapper.text()).toContain('User growth');
        expect(wrapper.text()).toContain('Active');
        expect(wrapper.text()).toContain('2 inactive');
        expect(wrapper.text()).toContain('User 7');
        expect(wrapper.text()).toContain('Primary database');
        expect(wrapper.text()).toContain('Module readiness');
        expect(wrapper.find('.mc-dashboard__widgets').exists()).toBe(true);
        expect(wrapper.find('.dashboard-users-table').exists()).toBe(true);
        expect(wrapper.find('a[href="/app/users/create"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/app/modules"]').exists()).toBe(true);
    });

    it('shows permission-safe empty sections without invented counts', async () => {
        get.mockResolvedValue({ data: { data: {
            ...dashboard,
            access: { users: false, audit: false, modules: false, health: false },
            metrics: { users: null, active_users: null, inactive_users: null, roles: null, permissions: null, modules: null },
            user_growth: [], recent_activity: [], health: null, module_readiness: {},
        } } });
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.text()).toContain('does not currently have access');
        expect(wrapper.text()).not.toContain('Total users');
        expect(wrapper.find('.dashboard-users-table').exists()).toBe(false);
        expect(wrapper.findAll('a[href="/app/users/create"]')).toHaveLength(0);
    });

    it('shows a loading state until the dashboard response arrives', async () => {
        let resolveRequest!: (value: unknown) => void;
        get.mockReturnValue(new Promise((resolve) => { resolveRequest = resolve; }));
        const wrapper = mountPage();
        expect(wrapper.get('[role="status"]').text()).toContain('Loading dashboard');
        expect(wrapper.findAll('.mc-dashboard__stats--skeleton > *')).toHaveLength(5);
        expect(wrapper.findAll('.mc-dashboard__widgets--skeleton > *')).toHaveLength(3);
        expect(wrapper.findAll('.mc-dashboard__lower--skeleton > *')).toHaveLength(2);
        resolveRequest({ data: { data: dashboard } });
        await flushPromises();
        expect(wrapper.text()).toContain('User growth');
        expect(wrapper.findAll('.mc-dashboard__widgets > *')).toHaveLength(3);
        expect(wrapper.find('.mc-dashboard__lower > .dashboard-users-table').exists()).toBe(true);
        expect(wrapper.find('[role="status"]').exists()).toBe(false);
    });

    it('shows a safe retry message when loading fails', async () => {
        get.mockResolvedValue(undefined);
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('could not be loaded');
        expect(wrapper.find('.mc-dashboard__stats--skeleton').exists()).toBe(true);
        expect(wrapper.findAll('.mc-dashboard__widgets--skeleton > *')).toHaveLength(3);
    });
});
