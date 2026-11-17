import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import SystemHealthPage from './SystemHealthPage.vue';
import { createSystemHealthRoutes } from '../routes';
import * as api from '../api';

vi.mock('../api', () => ({
    configureSystemHealthApi: vi.fn(),
    getSystemHealth: vi.fn(),
    systemHealthApiError: vi.fn((error: { status?: number }) => ({
        status: error.status,
        message: error.status === 403
            ? 'You do not have permission to view system readiness.'
            : error.status === 401
                ? 'Your session has expired. Sign in again to continue.'
                : 'System readiness checks could not be loaded. Please try again.',
    })),
}));

describe('SystemHealthPage', () => {
    it('registers the page in the existing authenticated shell with the view ability', () => {
        expect(createSystemHealthRoutes('/custom/v2/system-health')).toEqual([expect.objectContaining({
            path: '/app/system-health',
            meta: { requiresAuth: true, requiresPermission: 'view-system-health' },
        })]);
    });

    it('shows successful and unavailable check results without mutation controls', async () => {
        vi.mocked(api.getSystemHealth).mockResolvedValue({
            checked_at: '2026-11-15T10:30:00+00:00',
            overall_status: 'unavailable',
            scope: 'Only the listed request and primary database checks are reported.',
            checks: [
                { key: 'application_request', name: 'Application request', status: 'healthy', outcome: 'successful', message: 'The request was handled.' },
                { key: 'primary_database', name: 'Primary database', status: 'unavailable', outcome: 'failed', message: 'The database did not answer.' },
            ],
        });

        const wrapper = mount(SystemHealthPage, { global: { stubs: { CoreButton: { template: '<button><slot /></button>' } } } });
        await flushPromises();

        expect(wrapper.text()).toContain('Reported status: Unavailable');
        expect(wrapper.text()).toContain('Primary database');
        expect(wrapper.text()).toContain('Unavailable · failed');
        expect(wrapper.findAll('button').map((button) => button.text())).toEqual(['Refresh checks']);
    });

    it('shows a clear 403 state and clears previously loaded diagnostics', async () => {
        vi.mocked(api.getSystemHealth).mockRejectedValue({ status: 403 });

        const wrapper = mount(SystemHealthPage, { global: { stubs: { CoreButton: true } } });
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('do not have permission');
        expect(wrapper.text()).not.toContain('Primary database');
    });

    it('shows a 401 state with a sign-in link', async () => {
        vi.mocked(api.getSystemHealth).mockRejectedValue({ status: 401 });

        const wrapper = mount(SystemHealthPage, { global: { stubs: { CoreButton: true } } });
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('session has expired');
        expect(wrapper.get('a').attributes('href')).toBe('/login');
    });

    it('renders an empty result state and a generic transport error', async () => {
        vi.mocked(api.getSystemHealth).mockResolvedValue({
            checked_at: '2026-11-15T10:30:00+00:00', overall_status: 'not_configured',
            scope: 'Only listed checks are reported.', checks: [],
        });
        const wrapper = mount(SystemHealthPage, { global: { stubs: { CoreButton: true } } });
        await flushPromises();
        expect(wrapper.text()).toContain('No checks are configured.');

        vi.mocked(api.getSystemHealth).mockRejectedValue(new Error('internal secret detail'));
        const failedWrapper = mount(SystemHealthPage, { global: { stubs: { CoreButton: true } } });
        await flushPromises();
        expect(failedWrapper.get('[role="alert"]').text()).toContain('could not be loaded');
        expect(failedWrapper.text()).not.toContain('internal secret detail');
    });
});
