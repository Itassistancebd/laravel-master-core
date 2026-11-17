import axios from 'axios';
import type { AuditEventRecord, AuditFilters, AuditPage } from './types';

interface ApiEnvelope<T> { success: boolean; data: T; meta?: AuditPage['meta'] }

const client = (base: string) => axios.create({ baseURL: base, withCredentials: true, withXSRFToken: true });
let api = client('/api/v1/audit-logs');

export function configureAuditLogsApi(base: string): void { api = client(base); }

export async function listAuditEvents(filters: AuditFilters): Promise<AuditPage> {
    const response = await api.get<ApiEnvelope<AuditEventRecord[]>>('', { params: filters });
    return { events: response.data.data, meta: response.data.meta ?? { current_page: 1, last_page: 1, per_page: 25, total: 0 } };
}

export async function getAuditEvent(id: string): Promise<AuditEventRecord> {
    const response = await api.get<ApiEnvelope<AuditEventRecord>>(`/${encodeURIComponent(id)}`);
    return response.data.data;
}

export function auditApiError(error: unknown): { status?: number; message: string } {
    if (axios.isAxiosError<{ message?: string }>(error)) {
        return { status: error.response?.status, message: error.response?.data?.message ?? 'Audit history could not be loaded.' };
    }
    return { message: 'Audit history could not be loaded.' };
}
