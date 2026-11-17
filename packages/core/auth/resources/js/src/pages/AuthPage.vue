<script setup lang="ts">
import axios, { type AxiosError } from 'axios';
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { CoreButton, CoreField, CoreTextInput } from '@itassistance-bd/ui';
import { authApi, authenticatedUser, loadAuthenticatedUser } from '../state';

const props = defineProps<{ resetToken?: string; resetEmail?: string }>();
const route = useRoute();
const router = useRouter();
const email = ref(props.resetEmail ?? '');
const password = ref('');
const passwordConfirmation = ref('');
const remember = ref(false);
const loading = ref(false);
const message = ref('');
const error = ref('');
const errors = ref<Record<string, string[]>>({});
const sessions = ref<Array<{ id: string; ip_address: string | null; user_agent: string | null; current: boolean }>>([]);
const sessionInventoryUnavailable = ref(false);
const notificationUnreadCount = ref<number | null>(null);
const notificationsApiBase = document.querySelector<HTMLElement>('#master-core-auth')?.dataset.notificationsApiBase;
const currentPage = computed(() => route.path);

function responseMessage(exception: unknown): string {
    return (exception as AxiosError<{ message?: string }>).response?.data?.message ?? 'We could not complete that request. Please try again.';
}

async function csrf(): Promise<void> {
    // Establish the host session and XSRF cookie before posting any state-changing auth request.
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

async function login(): Promise<void> {
    loading.value = true;
    error.value = '';
    errors.value = {};
    try {
        await csrf();
        await axios.post('/login', { email: email.value, password: password.value, remember: remember.value });
        await loadAuthenticatedUser();
        const target = typeof route.query.redirect === 'string' ? route.query.redirect : '/app';
        await router.replace(target.startsWith('/') && !target.startsWith('//') ? target : '/app');
    } catch (exception) {
        errors.value = (exception as AxiosError<{ errors?: Record<string, string[]> }>).response?.data?.errors ?? {};
        error.value = responseMessage(exception);
    } finally {
        loading.value = false;
    }
}

async function requestReset(): Promise<void> {
    loading.value = true;
    message.value = '';
    error.value = '';
    try {
        await csrf();
        const response = await axios.post('/forgot-password', { email: email.value });
        message.value = response.data.message ?? 'If that email is registered, a reset link has been sent.';
    } catch (exception) {
        error.value = responseMessage(exception);
    } finally {
        loading.value = false;
    }
}

async function resetPassword(): Promise<void> {
    loading.value = true;
    error.value = '';
    errors.value = {};
    try {
        await csrf();
        const response = await axios.post('/reset-password', {
            email: email.value,
            password: password.value,
            password_confirmation: passwordConfirmation.value,
            token: props.resetToken,
        });
        message.value = response.data.message ?? 'Password updated. You can now sign in.';
        await router.replace('/login');
    } catch (exception) {
        errors.value = (exception as AxiosError<{ errors?: Record<string, string[]> }>).response?.data?.errors ?? {};
        error.value = responseMessage(exception);
    } finally {
        loading.value = false;
    }
}

async function resendVerification(): Promise<void> {
    loading.value = true;
    try {
        const response = await axios.post('/email/verification-notification');
        message.value = response.data.message ?? 'A verification link has been sent.';
        error.value = '';
    } catch (exception) {
        error.value = responseMessage(exception);
    } finally {
        loading.value = false;
    }
}

async function confirmPassword(): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
        await csrf();
        const response = await axios.post('/user/confirm-password', { password: password.value });
        message.value = response.data.message ?? 'Password confirmed.';
        password.value = '';
        const destination = typeof route.query.redirect === 'string' ? route.query.redirect : '';
        if (destination.startsWith('/') && !destination.startsWith('//')) await router.replace(destination);
    } catch (exception) {
        error.value = responseMessage(exception);
    } finally {
        loading.value = false;
    }
}

async function logout(): Promise<void> {
    loading.value = true;
    try {
        await axios.post('/logout');
        authenticatedUser.value = null;
        await router.replace('/login');
    } catch (exception) {
        error.value = responseMessage(exception);
    } finally {
        loading.value = false;
    }
}

async function loadSessions(): Promise<void> {
    try {
        const response = await authApi.get('/sessions');
        sessions.value = response.data.data;
    } catch (exception) {
        sessionInventoryUnavailable.value = (exception as AxiosError).response?.status === 501;
    }
}

async function revokeSession(id: string): Promise<void> {
    loading.value = true;
    try {
        await authApi.delete(`/sessions/${id}`);
        await loadSessions();
    } catch (exception) {
        error.value = responseMessage(exception);
    } finally {
        loading.value = false;
    }
}

async function loadNotificationCount(): Promise<void> {
    if (!notificationsApiBase) return;
    try {
        const response = await axios.get(`${notificationsApiBase}/unread-count`, { withCredentials: true });
        const count = response.data?.data?.unread_count;
        notificationUnreadCount.value = Number.isInteger(count) && count >= 0 ? count : null;
    } catch {
        notificationUnreadCount.value = null;
    }
}

onMounted(() => {
    if (currentPage.value === '/app/account-security') {
        void loadSessions();
        void loadNotificationCount();
    }
});
</script>

<template>
    <main v-if="currentPage === '/app/account-security'" class="auth-app">
        <RouterLink class="auth-link" to="/app/notifications">Notification Center <span v-if="notificationUnreadCount !== null" class="auth-badge">{{ notificationUnreadCount }}</span></RouterLink>
        <section class="auth-card auth-card--wide">
            <h2>Signed-in sessions</h2>
            <p class="auth-description">Review active browser sessions. The current session cannot be revoked here.</p>
            <p v-if="sessionInventoryUnavailable" class="auth-empty">Session inventory is available when the host uses Laravel's database session driver.</p>
            <p v-else-if="sessions.length === 0" class="auth-empty">No other session records are available.</p>
            <ul v-else class="auth-session-list">
                <li v-for="session in sessions" :key="session.id" class="auth-session">
                    <div><strong>{{ session.current ? 'This device' : (session.user_agent ?? 'Other session') }}</strong><span>{{ session.ip_address ?? 'IP unavailable' }}</span></div>
                    <span v-if="session.current" class="auth-badge">Current</span>
                    <CoreButton v-else variant="danger" :loading="loading" @click="revokeSession(session.id)">Revoke</CoreButton>
                </li>
            </ul>
            <CoreButton variant="secondary" @click="loadSessions">Refresh sessions</CoreButton>
            <p v-if="error" class="auth-notice auth-notice--error" role="alert">{{ error }}</p>
        </section>
        <button class="auth-link auth-app__verification" @click="router.push('/email/verify')">Email verification</button>
    </main>

    <main v-else class="auth-layout">
        <section class="auth-card" aria-labelledby="auth-title">
            <p class="auth-eyebrow">Master Core</p>
            <h1 id="auth-title">{{ currentPage === '/login' ? 'Sign in' : currentPage === '/forgot-password' ? 'Reset your password' : currentPage.startsWith('/reset-password') ? 'Choose a new password' : currentPage === '/user/confirm-password' ? 'Confirm your password' : 'Verify your email' }}</h1>
            <p class="auth-description">{{ currentPage === '/login' ? 'Use your account email and password to continue.' : currentPage === '/forgot-password' ? 'We will email a password reset link if the address is registered.' : currentPage.startsWith('/reset-password') ? 'Use a strong password that you do not reuse elsewhere.' : currentPage === '/user/confirm-password' ? 'Confirm your password to continue with this protected action.' : 'Check your inbox and follow the verification link to confirm your address.' }}</p>

            <form v-if="currentPage === '/login'" class="auth-form" @submit.prevent="login">
                <CoreField v-slot="{ id, describedBy, errorId }" label="Email address" required :error="errors.email?.[0]">
                    <CoreTextInput :id="id" v-model="email" type="email" autocomplete="username" required :invalid="Boolean(errorId)" :aria-describedby="describedBy" />
                </CoreField>
                <CoreField v-slot="{ id, describedBy, errorId }" label="Password" required :error="errors.password?.[0]">
                    <CoreTextInput :id="id" v-model="password" type="password" autocomplete="current-password" required :invalid="Boolean(errorId)" :aria-describedby="describedBy" />
                </CoreField>
                <label class="auth-remember"><input v-model="remember" type="checkbox"> Remember me</label>
                <p v-if="error" class="auth-notice auth-notice--error" role="alert">{{ error }}</p>
                <CoreButton type="submit" variant="primary" :loading="loading">Sign in</CoreButton>
                <RouterLink class="auth-link" to="/forgot-password">Forgot your password?</RouterLink>
            </form>

            <form v-else-if="currentPage === '/forgot-password'" class="auth-form" @submit.prevent="requestReset">
                <CoreField v-slot="{ id }" label="Email address" required><CoreTextInput :id="id" v-model="email" type="email" autocomplete="email" required /></CoreField>
                <p v-if="message" class="auth-notice auth-notice--success" role="status">{{ message }}</p>
                <p v-if="error" class="auth-notice auth-notice--error" role="alert">{{ error }}</p>
                <CoreButton type="submit" variant="primary" :loading="loading">Send reset link</CoreButton>
                <RouterLink class="auth-link" to="/login">Back to sign in</RouterLink>
            </form>

            <form v-else-if="currentPage.startsWith('/reset-password')" class="auth-form" @submit.prevent="resetPassword">
                <CoreField v-slot="{ id, errorId }" label="Email address" required :error="errors.email?.[0]"><CoreTextInput :id="id" v-model="email" type="email" autocomplete="email" required :invalid="Boolean(errorId)" /></CoreField>
                <CoreField v-slot="{ id, errorId }" label="New password" required :error="errors.password?.[0]"><CoreTextInput :id="id" v-model="password" type="password" autocomplete="new-password" minlength="12" required :invalid="Boolean(errorId)" /></CoreField>
                <CoreField v-slot="{ id }" label="Confirm password" required><CoreTextInput :id="id" v-model="passwordConfirmation" type="password" autocomplete="new-password" required /></CoreField>
                <p v-if="error" class="auth-notice auth-notice--error" role="alert">{{ error }}</p>
                <CoreButton type="submit" variant="primary" :loading="loading">Update password</CoreButton>
            </form>

            <form v-else-if="currentPage === '/user/confirm-password'" class="auth-form" @submit.prevent="confirmPassword">
                <CoreField v-slot="{ id }" label="Current password" required>
                    <CoreTextInput :id="id" v-model="password" type="password" autocomplete="current-password" required />
                </CoreField>
                <p v-if="message" class="auth-notice auth-notice--success" role="status">{{ message }}</p>
                <p v-if="error" class="auth-notice auth-notice--error" role="alert">{{ error }}</p>
                <CoreButton type="submit" variant="primary" :loading="loading">Confirm password</CoreButton>
            </form>

            <div v-else class="auth-form">
                <p v-if="authenticatedUser?.email_verified_at" class="auth-notice auth-notice--success" role="status">Your email address is verified.</p>
                <p v-else class="auth-notice" role="status">Your email address is not verified yet.</p>
                <p v-if="message" class="auth-notice auth-notice--success" role="status">{{ message }}</p>
                <p v-if="error" class="auth-notice auth-notice--error" role="alert">{{ error }}</p>
                <CoreButton v-if="!authenticatedUser?.email_verified_at" variant="primary" :loading="loading" @click="resendVerification">Resend verification link</CoreButton>
                <CoreButton variant="secondary" :loading="loading" @click="logout">Sign out</CoreButton>
            </div>
            <footer class="auth-card__footer">Secure sign-in provided by Laravel.</footer>
        </section>
    </main>
</template>
