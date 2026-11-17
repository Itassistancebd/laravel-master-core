<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { CoreButton, CoreConfirmDialog, CoreToast } from '@itassistance-bd/ui';
import { getCapabilities, getUser, resendVerification, sendPasswordReset, trashUser, updateUserStatus, userApiErrorMessage } from '../api';
import type { ManagedUser, UserCapabilities } from '../types';

const route = useRoute();
const user = ref<ManagedUser | null>(null);
const capabilities = ref<UserCapabilities | null>(null);
const loading = ref(false);
const error = ref('');
const toast = ref('');
const confirmAction = ref<'activate' | 'deactivate' | 'trash' | null>(null);

async function load(): Promise<void> {
    try {
        user.value = await getUser(route.params.user as string);
    } catch (exception) {
        error.value = userApiErrorMessage(exception);
    }
}

onMounted(async () => {
    try { capabilities.value = await getCapabilities(); } catch { capabilities.value = null; }
    await load();
});

async function performAction(): Promise<void> {
    if (!user.value || !confirmAction.value) return;
    loading.value = true;
    try {
        if (confirmAction.value === 'trash') {
            await trashUser(user.value.id);
            toast.value = 'User moved to trash.';
        } else {
            await updateUserStatus(user.value.id, confirmAction.value === 'activate');
            toast.value = `User ${confirmAction.value}d.`;
        }
        confirmAction.value = null;
        await load();
    } catch (exception) {
        error.value = userApiErrorMessage(exception);
    } finally {
        loading.value = false;
    }
}

async function requestPasswordReset(): Promise<void> {
    if (!user.value) return;
    loading.value = true;
    try { await sendPasswordReset(user.value.id); toast.value = 'Password setup/reset notification requested.'; }
    catch (exception) { error.value = userApiErrorMessage(exception); }
    finally { loading.value = false; }
}

async function requestVerification(): Promise<void> {
    if (!user.value) return;
    loading.value = true;
    try { await resendVerification(user.value.id); toast.value = 'Verification notification requested.'; }
    catch (exception) { error.value = userApiErrorMessage(exception); }
    finally { loading.value = false; }
}
</script>

<template>
    <section class="w-full min-w-0 rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div><h2 class="text-xl font-semibold text-slate-900">User details</h2><p class="mt-1 text-sm text-slate-600">Account information and permitted administrative actions.</p></div>
            <RouterLink class="text-sm text-blue-700 hover:underline" to="/app/users">Back to users</RouterLink>
        </div>
        <p v-if="error" class="mt-5 rounded bg-red-50 p-3 text-sm text-red-800" role="alert">{{ error }}</p>
        <p v-else-if="!user" class="mt-5 text-sm text-slate-600" role="status">Loading user details…</p>
        <template v-else>
            <dl class="mt-6 grid gap-4 sm:grid-cols-2">
                <div><dt class="text-sm text-slate-500">Name</dt><dd class="mt-1 font-medium text-slate-900">{{ user.name }}</dd></div>
                <div><dt class="text-sm text-slate-500">Email</dt><dd class="mt-1 font-medium text-slate-900">{{ user.email }}</dd></div>
                <div><dt class="text-sm text-slate-500">Email verification</dt><dd class="mt-1 text-slate-900">{{ user.email_verified_at ? 'Verified' : 'Unverified' }}</dd></div>
                <div><dt class="text-sm text-slate-500">Account status</dt><dd class="mt-1 text-slate-900">{{ user.is_active ? 'Active' : 'Inactive' }}</dd></div>
                <div><dt class="text-sm text-slate-500">Created</dt><dd class="mt-1 text-slate-900">{{ user.created_at ? new Date(user.created_at).toLocaleString() : '—' }}</dd></div>
            </dl>
            <div class="mt-7 flex flex-wrap gap-3">
                <RouterLink v-if="capabilities?.update" class="mc-button mc-button--secondary" :to="`/app/users/${user.id}/edit`">Edit user</RouterLink>
                <RouterLink v-if="capabilities?.manage_roles" class="mc-button mc-button--secondary" :to="`/app/users/${user.id}/roles`">Manage roles</RouterLink>
                <CoreButton v-if="capabilities?.status" variant="secondary" :loading="loading" @click="confirmAction = user?.is_active ? 'deactivate' : 'activate'">{{ user.is_active ? 'Deactivate' : 'Activate' }}</CoreButton>
                <CoreButton v-if="capabilities?.delete" variant="danger" :loading="loading" @click="confirmAction = 'trash'">Move to trash</CoreButton>
                <CoreButton v-if="capabilities?.password_reset" variant="secondary" :loading="loading" @click="requestPasswordReset">Send password setup/reset link</CoreButton>
                <CoreButton v-if="capabilities?.verification && !user.email_verified_at" variant="secondary" :loading="loading" @click="requestVerification">Resend verification email</CoreButton>
            </div>
        </template>
        <CoreToast v-if="toast" :message="toast" @dismiss="toast = ''" />
        <CoreConfirmDialog
            :open="confirmAction !== null"
            title="Confirm account action"
            :message="confirmAction === 'trash' ? 'Move this account to trash? It can be restored later.' : `Are you sure you want to ${confirmAction} this account?`"
            :confirm-label="confirmAction === 'trash' ? 'Move to trash' : 'Confirm'"
            :destructive="confirmAction === 'trash' || confirmAction === 'deactivate'"
            @cancel="confirmAction = null"
            @confirm="performAction"
        />
    </section>
</template>
