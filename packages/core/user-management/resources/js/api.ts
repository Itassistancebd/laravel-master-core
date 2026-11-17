import axios from 'axios';
import type { ApiEnvelope, BulkUserOperation, ManagedUser, UserCapabilities, UserImportPreview, UserImportResult, UserPageMeta } from './types';

function createApi(apiBase: string) {
    const client = axios.create({ baseURL: apiBase, withCredentials: true, withXSRFToken: true });
    client.interceptors.response.use((response) => response, (error: unknown) => {
        if (axios.isAxiosError(error) && error.response?.status === 401 && typeof window !== 'undefined') {
            const returnPath = `${window.location.pathname}${window.location.search}`;
            window.location.assign(`/login?redirect=${encodeURIComponent(returnPath)}`);
        }

        return Promise.reject(error);
    });

    return client;
}

let api = createApi('/api/v1/users');

export function configureUserManagementApi(apiBase: string): void {
    api = createApi(apiBase);
}

export async function listUsers(params: Record<string, string | number | undefined>, trash = false, signal?: AbortSignal): Promise<{ users: ManagedUser[]; meta: UserPageMeta }> {
    const response = await api.get<ApiEnvelope<ManagedUser[]>>(trash ? '/trash' : '/', { params, signal });
    return { users: response.data.data, meta: response.data.meta as UserPageMeta };
}

export async function exportUsers(params: Record<string, string | number | undefined>): Promise<Blob> {
    const response = await api.get('/export.csv', { params, responseType: 'blob' });
    return response.data as Blob;
}

export async function downloadUserImportTemplate(): Promise<Blob> {
    const response = await api.get('/import-template.csv', { responseType: 'blob' });
    return response.data as Blob;
}

export async function previewUserImport(file: File): Promise<UserImportPreview> {
    const form = new FormData();
    form.append('file', file);
    const response = await api.post<ApiEnvelope<UserImportPreview>>('/import/preview', form);
    return response.data.data;
}

export async function importUsers(file: File, previewToken: string): Promise<UserImportResult> {
    const form = new FormData();
    form.append('file', file);
    form.append('preview_token', previewToken);
    const response = await api.post<ApiEnvelope<UserImportResult>>('/import', form);
    return response.data.data;
}

export async function getUser(id: number | string): Promise<ManagedUser> {
    const response = await api.get<ApiEnvelope<ManagedUser>>(`/${encodeURIComponent(id)}`);
    return response.data.data;
}

export async function getCapabilities(): Promise<UserCapabilities> {
    const response = await api.get<ApiEnvelope<UserCapabilities>>('/capabilities');
    return response.data.data;
}

export async function createUser(input: Pick<ManagedUser, 'name' | 'email'>): Promise<ManagedUser> {
    const response = await api.post<ApiEnvelope<ManagedUser>>('/', input);
    return response.data.data;
}

export async function updateUser(id: number | string, input: Pick<ManagedUser, 'name' | 'email'>): Promise<ManagedUser> {
    const response = await api.put<ApiEnvelope<ManagedUser>>(`/${encodeURIComponent(id)}`, input);
    return response.data.data;
}

export async function updateUserStatus(id: number | string, isActive: boolean): Promise<void> {
    await api.patch(`/${encodeURIComponent(id)}/status`, { is_active: isActive });
}

export async function trashUser(id: number | string): Promise<void> {
    await api.delete(`/${encodeURIComponent(id)}`);
}

export async function restoreUser(id: number | string): Promise<void> {
    await api.post(`/${encodeURIComponent(id)}/restore`);
}

export async function permanentlyDeleteUser(id: number | string, confirmation: string): Promise<void> {
    await api.delete(`/${encodeURIComponent(id)}/force`, { data: { confirmation } });
}

export async function sendPasswordReset(id: number | string): Promise<void> {
    await api.post(`/${encodeURIComponent(id)}/password-reset`);
}

export async function resendVerification(id: number | string): Promise<void> {
    await api.post(`/${encodeURIComponent(id)}/verification-notification`);
}

export async function bulkUsers(operation: BulkUserOperation, ids: Array<string | number>): Promise<number> {
    const response = await api.post<ApiEnvelope<{ affected: number }>>(`/bulk/${operation}`, { ids });
    return response.data.data.affected;
}

export function userApiErrorMessage(error: unknown): string {
    if (axios.isAxiosError<{ message?: string }>(error)) {
        if (error.response?.data instanceof Blob) {
            return 'The request failed. Please check your access and try again.';
        }
        return error.response?.data?.message ?? 'We could not complete that request. Please try again.';
    }

    return 'We could not complete that request. Please try again.';
}

export function userApiValidationErrors(error: unknown): Record<string, string[]> {
    if (axios.isAxiosError<{ errors?: Record<string, string[]> }>(error)) {
        return error.response?.data?.errors ?? {};
    }

    return {};
}
