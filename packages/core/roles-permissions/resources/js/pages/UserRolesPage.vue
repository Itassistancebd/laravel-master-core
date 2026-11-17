<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, RouterLink } from 'vue-router';
import { CoreButton, CoreFormSection, CoreToast } from '@itassistance-bd/ui';
import { getUserRoles, listRoles, rolesPermissionsErrorMessage, syncUserRoles } from '../api';
import type { AssignedUserRoles, RoleRecord } from '../types';

const route = useRoute();
const user = ref<AssignedUserRoles['user'] | null>(null);
const assignedIds = ref<number[]>([]);
const roles = ref<RoleRecord[]>([]);
const loading = ref(false);
const error = ref('');
const toast = ref('');

async function load(): Promise<void> {
    loading.value = true;
    try {
        const [assignment, page] = await Promise.all([getUserRoles(route.params.user as string), listRoles({ per_page: 100 })]);
        user.value = assignment.user;
        assignedIds.value = assignment.role_ids;
        roles.value = page.roles;
    } catch (exception) { error.value = rolesPermissionsErrorMessage(exception); }
    finally { loading.value = false; }
}

onMounted(load);

async function save(): Promise<void> {
    // The server validates grant scope and treats the protected Super Admin role separately.
    loading.value = true;
    error.value = '';
    try {
        assignedIds.value = await syncUserRoles(route.params.user as string, assignedIds.value);
        toast.value = 'Role assignments saved.';
    } catch (exception) { error.value = rolesPermissionsErrorMessage(exception); }
    finally { loading.value = false; }
}
</script>

<template>
    <main class="max-w-3xl rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        <div class="flex flex-wrap items-start justify-between gap-4"><div><h1 class="text-xl font-semibold text-slate-900">User roles</h1><p class="mt-1 text-sm text-slate-600">{{ user?.name }} · {{ user?.email }}</p></div><RouterLink class="text-sm text-blue-700 hover:underline" :to="`/app/users/${route.params.user}`">Back to user</RouterLink></div>
        <p v-if="error" class="mt-5 rounded bg-red-50 p-3 text-sm text-red-800" role="alert">{{ error }}</p>
        <div v-else class="mt-5">
            <CoreFormSection number="1" title="Role assignments" description="Choose the roles assigned to this user. Protected Super Admin rules are enforced by the server." :columns="1">
                <label v-for="role in roles" :key="role.id" class="flex items-start gap-3 rounded border border-slate-200 p-3">
                    <input v-model="assignedIds" type="checkbox" :value="role.id" class="mt-1 rounded border-slate-300">
                    <span><span class="font-medium text-slate-900">{{ role.name }}</span><span class="block text-xs text-slate-500">{{ role.role_key === 'core-super-admin' ? 'Provisioning is console-only. Removal requires Super Admin authority and last-admin checks.' : `${role.permissions_count} registered permissions` }}</span></span>
                </label>
                <p v-if="roles.length === 0" class="text-sm text-slate-600">No roles are available.</p>
                <div><CoreButton :loading="loading" @click="save">Save assignments</CoreButton></div>
            </CoreFormSection>
        </div>
        <CoreToast v-if="toast" :message="toast" @dismiss="toast = ''" />
    </main>
</template>
