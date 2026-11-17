import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import AuditLogsPage from './AuditLogsPage.vue';
import { createAuditLogRoutes } from '../routes';

const api = vi.hoisted(() => ({ listAuditEvents: vi.fn(), getAuditEvent: vi.fn(), configureAuditLogsApi: vi.fn() }));
vi.mock('../api', () => ({ ...api, auditApiError: (error: { status?: number }) => ({ status: error.status, message: 'Could not load audit history.' }) }));

describe('Audit Logs page', () => {
    it('registers a permission-protected read-only route', () => {
        const route = createAuditLogRoutes('/api/v1/audit-logs')[0];
        expect(route.meta).toMatchObject({ requiresAuth: true, requiresPermission: 'view-audit-logs' });
        expect(api.configureAuditLogsApi).toHaveBeenCalledWith('/api/v1/audit-logs');
    });

    it('shows events and exposes only a read detail action', async () => {
        api.listAuditEvents.mockResolvedValue({ events: [{
            id: '01J00000000000000000000000', event_name: 'auth.login.succeeded', category: 'authentication',
            outcome: 'succeeded', source: 'http', actor: null, subject: null, correlation_id: null,
            request_method: 'POST', route_name: 'login', ip_address: null, user_agent: null,
            before_values: null, after_values: null, metadata: null,
            occurred_at: '2026-11-16T00:00:00Z', created_at: '2026-11-16T00:00:00Z',
        }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } });
        const wrapper = mount(AuditLogsPage, { global: { stubs: { CoreDataTable: { props: ['rows'], template: '<div><span v-for="row in rows" :key="row.id">{{ row.event_name }}</span><slot name="actions" :row="rows[0]" /></div>' } } } });
        await flushPromises();

        expect(wrapper.text()).toContain('auth.login.succeeded');
        expect(wrapper.findAll('button').map((button) => button.text())).toContain('View');
        expect(wrapper.text()).not.toMatch(/Delete|Edit|Export/);
    });

    it('shows a clear 403 state and does not expose an export action', async () => {
        api.listAuditEvents.mockRejectedValue({ status: 403 });
        const wrapper = mount(AuditLogsPage);
        await flushPromises();
        expect(wrapper.text()).toContain('You do not have permission to view audit history.');
        expect(wrapper.text()).not.toContain('Export');
    });
});
