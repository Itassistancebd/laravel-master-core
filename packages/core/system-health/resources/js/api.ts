import axios from 'axios';
import type { SystemHealthSnapshot } from './types';

interface ApiEnvelope<T> { success: boolean; data: T }

const client = (base: string) => axios.create({ baseURL: base, withCredentials: true, withXSRFToken: true });
let api = client('/api/v1/system-health');

export function configureSystemHealthApi(base: string): void {
    api = client(base);
}

export async function getSystemHealth(): Promise<SystemHealthSnapshot> {
    const response = await api.get<ApiEnvelope<SystemHealthSnapshot>>('');
    return response.data.data;
}

export function systemHealthApiError(error: unknown): { status?: number; message: string } {
    if (axios.isAxiosError(error)) {
        const status = error.response?.status;
        return {
            status,
            message: status === 401
                ? 'Your session has expired. Sign in again to continue.'
                : status === 403
                    ? 'You do not have permission to view system readiness.'
                    : 'System readiness checks could not be loaded. Please try again.',
        };
    }

    return { message: 'System readiness checks could not be loaded. Please try again.' };
}
