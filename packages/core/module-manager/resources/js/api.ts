import axios from 'axios';
import type { ModuleFilters, ModulePage, ModulePageMeta, ModuleRecord } from './types';

interface ApiEnvelope<T> { success: boolean; data: T; meta?: ModulePageMeta }

const client = (base: string) => axios.create({ baseURL: base, withCredentials: true, withXSRFToken: true });
let api = client('/api/v1/modules');

export function configureModuleManagerApi(base: string): void {
    api = client(base);
}

export async function listModules(filters: ModuleFilters): Promise<ModulePage> {
    const response = await api.get<ApiEnvelope<ModuleRecord[]>>('', { params: filters });
    return {
        modules: response.data.data,
        meta: response.data.meta ?? { current_page: 1, last_page: 1, per_page: 10, total: 0 },
    };
}

export async function getModule(key: string): Promise<ModuleRecord> {
    const response = await api.get<ApiEnvelope<ModuleRecord>>(`/${encodeURIComponent(key)}`);
    return response.data.data;
}

export function moduleApiError(error: unknown): { status?: number; message: string } {
    if (axios.isAxiosError<{ message?: string }>(error)) {
        const status = error.response?.status;
        return {
            status,
            message: status === 401
                ? 'Your session has expired. Sign in again to continue.'
                : error.response?.data?.message ?? 'The module catalog could not be loaded.',
        };
    }

    return { message: 'The module catalog could not be loaded.' };
}
