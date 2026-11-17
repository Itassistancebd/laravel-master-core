import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import NotificationCenterPage from './NotificationCenterPage.vue';
import { createNotificationsRoutes } from '../routes';
import * as api from '../api';

vi.mock('../api', () => ({
    configureNotificationsApi: vi.fn(),
    listNotifications: vi.fn(),
    getUnreadCount: vi.fn(),
    setNotificationRead: vi.fn(),
    markAllNotificationsRead: vi.fn(),
    notificationsApiError: vi.fn((error: { status?: number }) => ({
        status: error.status,
        message: error.status === 401
            ? 'Your session has expired. Sign in again to view notifications.'
            : error.status === 403
                ? 'Your account cannot access notifications right now.'
                : 'Notifications could not be loaded or updated. Please try again.',
    })),
}));

const page = {
    items: [{ id: '01JBA7N8XK6Q8Q6W7BY8GFD63M', event_key: 'account.roles.changed', title: 'Account access updated', message: 'Your account access was updated.', read_at: null, created_at: '2026-11-15T10:00:00Z' }],
    current_page: 1, last_page: 1, per_page: 25, total: 1,
};

const buttonStubs = {
    CoreButton: { template: '<button @click="$attrs.onClick"><slot /></button>' },
    RouterLink: { template: '<a><slot /></a>' },
};

describe('NotificationCenterPage', () => {
    it('registers an authenticated self-service route without an RBAC permission', () => {
        expect(createNotificationsRoutes('/custom/v2/notifications')).toEqual([expect.objectContaining({
            path: '/app/notifications',
            meta: { requiresAuth: true },
        })]);
        expect(api.configureNotificationsApi).toHaveBeenCalledWith('/custom/v2/notifications');
    });

    it('shows the unread notice and read-state actions without edit/delete/export controls', async () => {
        vi.mocked(api.listNotifications).mockResolvedValue(page);
        vi.mocked(api.getUnreadCount).mockResolvedValue(1);
        const wrapper = mount(NotificationCenterPage, { global: { stubs: buttonStubs } });
        await flushPromises();

        expect(wrapper.text()).toContain('Unread: 1');
        expect(wrapper.text()).toContain('Your account access was updated.');
        expect(wrapper.text()).toContain('Mark read');
        expect(wrapper.text()).not.toMatch(/\b(edit|delete|export)\b/i);
    });

    it('marks one notice and all notices read and refreshes the count', async () => {
        vi.mocked(api.listNotifications).mockResolvedValue(page);
        vi.mocked(api.getUnreadCount).mockResolvedValue(1);
        vi.mocked(api.setNotificationRead).mockResolvedValue({ ...page.items[0], read_at: '2026-11-15T10:01:00Z' });
        vi.mocked(api.markAllNotificationsRead).mockResolvedValue({ updated: 1, remaining: 0 });
        const wrapper = mount(NotificationCenterPage, { global: { stubs: buttonStubs } });
        await flushPromises();
        const markRead = wrapper.findAll('button').find((button) => button.text().includes('Mark read'));
        await markRead?.trigger('click');
        await flushPromises();
        expect(api.setNotificationRead).toHaveBeenCalledWith(page.items[0].id, true);

        const markAll = wrapper.findAll('button').find((button) => button.text().includes('Mark all as read'));
        await markAll?.trigger('click');
        await flushPromises();
        expect(api.markAllNotificationsRead).toHaveBeenCalledOnce();
    });

    it('shows a safe 403 failure state and clears notification content', async () => {
        vi.mocked(api.listNotifications).mockRejectedValue({ status: 403 });
        vi.mocked(api.getUnreadCount).mockResolvedValue(0);
        const wrapper = mount(NotificationCenterPage, { global: { stubs: { ...buttonStubs, CoreButton: true } } });
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('cannot access notifications');
        expect(wrapper.text()).not.toContain('Your account access was updated.');
    });

    it('renders an empty state and five-link pagination for long lists', async () => {
        vi.mocked(api.listNotifications).mockResolvedValue({ ...page, items: [], total: 0 });
        vi.mocked(api.getUnreadCount).mockResolvedValue(0);
        const empty = mount(NotificationCenterPage, { global: { stubs: { ...buttonStubs, CoreButton: true } } });
        await flushPromises();
        expect(empty.text()).toContain('No notifications');

        vi.mocked(api.listNotifications).mockResolvedValue({ ...page, current_page: 5, last_page: 12, total: 300 });
        const long = mount(NotificationCenterPage, { global: { stubs: buttonStubs } });
        await flushPromises();
        expect(long.findAll('nav button').length).toBe(7); // Previous, five page links, Next.
    });
});
