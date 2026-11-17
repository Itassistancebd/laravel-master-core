import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { api, axiosMock } = vi.hoisted(() => {
    const api = { get: vi.fn(), delete: vi.fn() };
    const axiosMock = {
        get: vi.fn(),
        post: vi.fn(),
        create: vi.fn(() => api),
        defaults: { headers: { common: {} }, withCredentials: false, withXSRFToken: false },
    };
    return { api, axiosMock };
});

vi.mock('axios', () => ({ default: axiosMock }));

import AuthPage from './AuthPage.vue';
import MasterCoreAuthApp from '../MasterCoreAuthApp.vue';
import { createAuthRouter } from '../router';
import { authenticatedUser } from '../state';

async function openPage(path: string, props: Record<string, string> = {}) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/login', component: AuthPage },
            { path: '/forgot-password', component: AuthPage },
            { path: '/reset-password/:token', component: AuthPage },
            { path: '/email/verify', component: AuthPage },
            { path: '/user/confirm-password', component: AuthPage },
            { path: '/app/:pathMatch(.*)*', component: AuthPage },
        ],
    });
    await router.push(path);
    await router.isReady();
    const wrapper = mount(AuthPage, { props, global: { plugins: [router] } });
    return { wrapper, router };
}

describe('Authentication screens', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        authenticatedUser.value = null;
        api.get.mockRejectedValue(new Error('not signed in'));
        axiosMock.get.mockResolvedValue({ data: {} });
    });

    it('renders labeled sign-in fields and submits credentials with CSRF bootstrap', async () => {
        api.get.mockResolvedValue({ data: { data: { id: 7, name: 'Test User', email: 'test@example.test', email_verified_at: null } } });
        axiosMock.post.mockResolvedValue({ data: { redirect: '/app' } });
        const { wrapper, router } = await openPage('/login?redirect=%2F%2Fevil.example');

        expect(wrapper.text()).toContain('Sign in');
        expect(wrapper.get('label[for]').text()).toContain('Email address');
        expect(wrapper.get('input[type="password"]').attributes('autocomplete')).toBe('current-password');
        await wrapper.get('input[type="email"]').setValue('test@example.test');
        await wrapper.get('input[type="password"]').setValue('StrongPass123');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(axiosMock.get).toHaveBeenCalledWith('/sanctum/csrf-cookie', { withCredentials: true });
        expect(axiosMock.post).toHaveBeenCalledWith('/login', {
            email: 'test@example.test', password: 'StrongPass123', remember: false,
        });
        expect(router.currentRoute.value.fullPath).toBe('/app');
    });

    it('shows generic sign-in errors and server validation feedback', async () => {
        axiosMock.post.mockRejectedValue({
            response: {
                data: {
                    message: 'The provided credentials are incorrect.',
                    errors: { email: ['The provided credentials are incorrect.'] },
                },
            },
        });
        const { wrapper } = await openPage('/login');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('provided credentials');
        expect(wrapper.text()).toContain('Forgot your password?');
    });

    it('posts a reset link request without disclosing account status in the UI', async () => {
        axiosMock.post.mockResolvedValue({ data: { message: 'If the address is eligible, a password reset link has been sent.' } });
        const { wrapper } = await openPage('/forgot-password');
        await wrapper.get('input[type="email"]').setValue('test@example.test');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(axiosMock.post).toHaveBeenCalledWith('/forgot-password', { email: 'test@example.test' });
        expect(wrapper.get('[role="status"]').text()).toContain('eligible');
    });

    it('submits a reset token and password confirmation', async () => {
        axiosMock.post.mockResolvedValue({ data: { message: 'Password updated.' } });
        const { wrapper, router } = await openPage('/reset-password/reset-token', { resetToken: 'reset-token' });
        await wrapper.get('input[type="email"]').setValue('test@example.test');
        const passwordFields = wrapper.findAll('input[type="password"]');
        await passwordFields[0].setValue('StrongPass123');
        await passwordFields[1].setValue('StrongPass123');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(axiosMock.post).toHaveBeenCalledWith('/reset-password', expect.objectContaining({
            email: 'test@example.test', token: 'reset-token', password: 'StrongPass123', password_confirmation: 'StrongPass123',
        }));
        expect(router.currentRoute.value.path).toBe('/login');
    });

    it('resends the verification email for the signed-in account', async () => {
        axiosMock.post.mockResolvedValue({ data: { message: 'A verification link has been sent.' } });
        const { wrapper } = await openPage('/email/verify');
        await wrapper.get('button.mc-button--primary').trigger('click');
        await flushPromises();

        expect(axiosMock.post).toHaveBeenCalledWith('/email/verification-notification');
        expect(wrapper.get('.auth-notice--success').text()).toContain('verification link');
    });

    it('confirms the current password through Fortify', async () => {
        axiosMock.post.mockResolvedValue({ data: '' });
        const { wrapper } = await openPage('/user/confirm-password');
        await wrapper.get('input[type="password"]').setValue('StrongPass123');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(axiosMock.post).toHaveBeenCalledWith('/user/confirm-password', { password: 'StrongPass123' });
        expect(wrapper.get('[role="status"]').text()).toContain('Password confirmed.');
    });

    it('logs out through Fortify and returns to the guest login route', async () => {
        authenticatedUser.value = { id: 7, name: 'Test User', email: 'test@example.test', email_verified_at: null };
        api.get.mockResolvedValue({ data: { data: [] } });
        axiosMock.post.mockResolvedValue({ data: '' });
        const { wrapper, router } = await openPage('/email/verify');
        await wrapper.get('button.mc-button--secondary').trigger('click');
        await flushPromises();

        expect(axiosMock.post).toHaveBeenCalledWith('/logout');
        expect(router.currentRoute.value.path).toBe('/login');
        expect(authenticatedUser.value).toBeNull();
    });

    it('shows the notification count badge on the existing account surface when configured', async () => {
        document.body.innerHTML = '<div id="master-core-auth" data-notifications-api-base="/api/v1/notifications"></div>';
        authenticatedUser.value = { id: 7, name: 'Test User', email: 'test@example.test', email_verified_at: null };
        axiosMock.get.mockResolvedValue({ data: { data: { unread_count: 3 } } });
        const { wrapper } = await openPage('/app/account-security');
        await flushPromises();

        expect(wrapper.text()).toContain('Notification Center');
        expect(wrapper.text()).toContain('3');
        expect(axiosMock.get).toHaveBeenCalledWith('/api/v1/notifications/unread-count', { withCredentials: true });
        wrapper.unmount();
        document.body.innerHTML = '';
    });

    it('redirects unauthenticated protected routes and redirects authenticated guests', async () => {
        window.history.replaceState({}, '', '/app/settings');
        api.get.mockRejectedValue(new Error('not signed in'));
        const guestShell = mount(MasterCoreAuthApp, { global: { plugins: [createAuthRouter('/api/v1/auth')] } });
        await flushPromises();
        expect(window.location.pathname).toBe('/login');
        guestShell.unmount();

        window.history.replaceState({}, '', '/login');
        api.get.mockImplementation((path: string) => path === '/user'
            ? Promise.resolve({ data: { data: { id: 9, name: 'Member', email: 'member@example.test', email_verified_at: null } } })
            : Promise.resolve({ data: { data: [] } }));
        const signedInShell = mount(MasterCoreAuthApp, { global: { plugins: [createAuthRouter('/api/v1/auth')] } });
        await flushPromises();
        expect(window.location.pathname).toBe('/app');
        signedInShell.unmount();
        window.history.replaceState({}, '', '/');
    });
});
