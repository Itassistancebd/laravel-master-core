<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { CoreDataTable } from '@itassistance-bd/ui';
import type { CoreDataTableColumn } from '@itassistance-bd/ui';
import { getCapabilities, listUsers, userApiErrorMessage } from '@itassistance-bd/user-management';
import type { ManagedUser, UserCapabilities, UserPageMeta } from '@itassistance-bd/user-management';

const columns: CoreDataTableColumn[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'email', label: 'Email', sortable: true },
    { key: 'email_verified_at', label: 'Email verification' },
    { key: 'is_active', label: 'Account status' },
    { key: 'created_at', label: 'Created', sortable: true },
];
const users = ref<ManagedUser[]>([]);
const capabilities = ref<UserCapabilities | null>(null);
const filters = reactive({ search: '', status: '', email_verified: '' });
const meta = ref<UserPageMeta>({ current_page: 1, per_page: 10, total: 0, last_page: 1, from: null, to: null });
const loading = ref(true);
const error = ref('');

function formatDate(value: string | null): string {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date);
}

async function load(): Promise<void> {
    if (!capabilities.value?.view) return;
    loading.value = true;
    error.value = '';
    try {
        const result = await listUsers({
            search: filters.search || undefined,
            status: filters.status || undefined,
            email_verified: filters.email_verified || undefined,
            sort_by: 'created_at',
            sort_direction: 'desc',
            page: meta.value.current_page,
            per_page: meta.value.per_page,
        });
        users.value = result.users;
        meta.value = result.meta;
    } catch (exception) {
        error.value = userApiErrorMessage(exception);
    } finally {
        loading.value = false;
    }
}

async function initialize(): Promise<void> {
    try { capabilities.value = await getCapabilities(); }
    catch { capabilities.value = null; error.value = 'User records could not be loaded for this account.'; loading.value = false; return; }
    if (capabilities.value.view) await load();
    else { loading.value = false; }
}
function search(): void { meta.value.current_page = 1; void load(); }
function pageChanged(page: number): void { meta.value.current_page = page; void load(); }
function pageSizeChanged(size: number): void { meta.value.per_page = size; meta.value.current_page = 1; void load(); }
onMounted(() => { void initialize(); });
</script>

<template>
    <article class="mc-panel mc-panel--users">
        <header class="mc-panel__heading mc-users-table__heading">
            <div><h2>Users</h2><p>Recently created accounts</p></div>
            <RouterLink v-if="capabilities?.create" to="/app/users/create" class="mc-button mc-button--primary mc-users-table__create">Create user <span aria-hidden="true">+</span></RouterLink>
        </header>
        <form v-if="capabilities?.view" class="mc-users-table__filters" role="search" @submit.prevent="search">
            <label class="mc-users-table__search"><span class="sr-only">Search users</span><input v-model="filters.search" type="search" placeholder="Search name or email" /></label>
            <label><span class="sr-only">Account status</span><select v-model="filters.status" aria-label="Account status"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
            <label><span class="sr-only">Email verification</span><select v-model="filters.email_verified" aria-label="Email verification"><option value="">All verification</option><option value="verified">Verified</option><option value="unverified">Unverified</option></select></label>
            <button class="mc-button mc-button--secondary" type="submit" :disabled="loading">Search</button>
        </form>
        <div v-if="!capabilities?.view && !loading" class="mc-panel__empty">Your account cannot view user records.</div>
        <CoreDataTable
            v-else
            :columns="columns"
            :rows="users"
            :page="meta.current_page"
            :last-page="meta.last_page"
            :total="meta.total"
            :per-page="meta.per_page"
            :loading="loading"
            :error="error"
            :selectable="false"
            empty-message="No user records match these filters."
            @update:page="pageChanged"
            @update:per-page="pageSizeChanged"
        >
            <template #cell-name="{ row }"><RouterLink class="mc-users-table__name" :to="`/app/users/${row.id}`">{{ row.name }}</RouterLink></template>
            <template #cell-email_verified_at="{ row }"><span class="mc-status-pill" :class="row.email_verified_at ? 'is-success' : 'is-muted'">{{ row.email_verified_at ? 'Verified' : 'Unverified' }}</span></template>
            <template #cell-is_active="{ row }"><span class="mc-status-pill" :class="row.is_active ? 'is-success' : 'is-muted'">{{ row.is_active ? 'Active' : 'Inactive' }}</span></template>
            <template #cell-created_at="{ row }">{{ formatDate(row.created_at) }}</template>
            <template #actions="{ row }"><details class="mc-users-table__action-menu"><summary>Actions</summary><div><RouterLink :to="`/app/users/${row.id}`">View</RouterLink><RouterLink v-if="capabilities?.update" :to="`/app/users/${row.id}/edit`">Edit</RouterLink></div></details></template>
        </CoreDataTable>
    </article>
</template>
