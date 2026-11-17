import axios from 'axios';
import type { CoreNotification, NotificationPage } from './types';

interface ApiEnvelope<T> { success: boolean; data: T }
interface PageEnvelope<T> { success: boolean; data: T[]; meta: Omit<NotificationPage, 'items'> }

const client = (base: string) => axios.create({ baseURL: base, withCredentials: true, withXSRFToken: true });
let api = client('/api/v1/notifications');

export function configureNotificationsApi(base: string): void {
    api = client(base);
}

export async function listNotifications(params: { status: 'all' | 'unread' | 'read'; per_page: number; page: number }): Promise<NotificationPage> {
    const response = await api.get<PageEnvelope<CoreNotification>>('', { params });
    return { items: response.data.data, ...response.data.meta };
}

export async function getUnreadCount(): Promise<number> {
    const response = await api.get<ApiEnvelope<{ unread_count: number }>>('/unread-count');
    return response.data.data.unread_count;
}

export async function setNotificationRead(id: string, read: boolean): Promise<CoreNotification> {
    const response = await api.patch<ApiEnvelope<CoreNotification>>(`/${encodeURIComponent(id)}/${read ? 'read' : 'unread'}`);
    return response.data.data;
}

export async function markAllNotificationsRead(): Promise<{ updated: number; remaining: number }> {
    let updated = 0;
    let remaining = 0;
    let batches = 0;

    // Bound one user gesture while allowing ordinary inboxes to finish over several small updates.
    do {
        const response = await api.post<ApiEnvelope<{ updated: number; remaining: number }>>('/read-all');
        updated += response.data.data.updated;
        remaining = response.data.data.remaining;
        batches += 1;
    } while (remaining > 0 && batches < 25);

    return { updated, remaining };
}

export function notificationsApiError(error: unknown): { status?: number; message: string } {
    if (axios.isAxiosError(error)) {
        const status = error.response?.status;
        return {
            status,
            message: status === 401
                ? 'Your session has expired. Sign in again to view notifications.'
                : status === 403
                    ? 'Your account cannot access notifications right now.'
                    : 'Notifications could not be loaded or updated. Please try again.',
        };
    }

    return { message: 'Notifications could not be loaded or updated. Please try again.' };
}
