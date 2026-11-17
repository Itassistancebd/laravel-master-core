import axios from 'axios';
import { ref } from 'vue';

export interface AuthUser {
    id: number | string;
    name: string | null;
    email: string;
    email_verified_at: string | null;
}

export const authenticatedUser = ref<AuthUser | null>(null);
let api = axios.create({ baseURL: '/api/v1/auth', withCredentials: true, withXSRFToken: true });

export function configureAuth(apiBase: string): void {
    api = axios.create({ baseURL: apiBase, withCredentials: true, withXSRFToken: true });
}

export async function loadAuthenticatedUser(): Promise<AuthUser | null> {
    try {
        const response = await api.get<{ data: AuthUser }>('/user');
        authenticatedUser.value = response.data.data;
        return authenticatedUser.value;
    } catch {
        authenticatedUser.value = null;
        return null;
    }
}

export { api as authApi };
