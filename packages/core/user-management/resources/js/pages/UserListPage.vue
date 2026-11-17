<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { CoreButton, CoreConfirmDialog, CoreDataTable, CoreField, CoreTextInput, CoreToast } from '@itassistance-bd/ui';
import type { CoreDataTableColumn } from '@itassistance-bd/ui';
import { bulkUsers, exportUsers, getCapabilities, listUsers, trashUser, updateUserStatus, userApiErrorMessage } from '../api';
import type { BulkUserOperation, ManagedUser, UserCapabilities, UserPageMeta } from '../types';
import UserImportDialog from './UserImportDialog.vue';
import type { UserImportResult } from '../types';

const columns: CoreDataTableColumn[] = [
    { key: 'name', label: 'Name', sortable: true, required: true },
    { key: 'email', label: 'Email', sortable: true },
    { key: 'email_verified_at', label: 'Email verification', sortable: true },
    { key: 'is_active', label: 'Account status', sortable: true },
    { key: 'created_at', label: 'Created', sortable: true },
];

const users = ref<ManagedUser[]>([]);
const capabilities = ref<UserCapabilities | null>(null);
const selectedIds = ref<Array<string | number>>([]);
const visibleColumnKeys = ref(columns.map((column) => column.key));
const filters = reactive({ search: '', status: '', email_verified: '' });
const sort = reactive({ by: 'created_at', direction: 'desc' as 'asc' | 'desc' });
const meta = ref<UserPageMeta>({ current_page: 1, per_page: 25, total: 0, last_page: 1, from: null, to: null });
const loading = ref(false);
const error = ref('');
const toast = ref('');
const toastVariant = ref<'success' | 'error'>('success');
const pending = ref<{ operation: string; id?: string | number } | null>(null);
const confirmOpen = ref(false);
const importOpen = ref(false);
const exporting = ref(false);
const activeFilterCount = () => Number(Boolean(filters.search.trim())) + Number(Boolean(filters.status)) + Number(Boolean(filters.email_verified));
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let activeController: AbortController | null = null;
let latestRequest = 0;
let lastRequestKey = '';

function requestKey(): string {
    return JSON.stringify({
        search: filters.search.trim(),
        status: filters.status,
        email_verified: filters.email_verified,
        sort_by: sort.by,
        sort_direction: sort.direction,
        page: meta.value.current_page,
        per_page: meta.value.per_page,
    });
}

async function load(force = false): Promise<void> {
    const key = requestKey();
    if (!force && key === lastRequestKey) return;
    lastRequestKey = key;
    const requestId = ++latestRequest;
    activeController?.abort();
    const controller = new AbortController();
    activeController = controller;
    loading.value = true;
    error.value = '';
    try {
        const result = await listUsers({
            search: filters.search.trim() || undefined,
            status: filters.status || undefined,
            email_verified: filters.email_verified || undefined,
            sort_by: sort.by,
            sort_direction: sort.direction,
            page: meta.value.current_page,
            per_page: meta.value.per_page,
        }, false, controller.signal);
        if (requestId !== latestRequest) return;
        users.value = result.users;
        meta.value = result.meta;
    } catch (exception) {
        if (requestId !== latestRequest) return;
        error.value = userApiErrorMessage(exception);
    } finally {
        if (requestId === latestRequest) loading.value = false;
    }
}

function clearSearchTimer(): void {
    if (searchTimer !== undefined) clearTimeout(searchTimer);
    searchTimer = undefined;
}

function applyFilters(): void {
    clearSearchTimer();
    meta.value.current_page = 1;
    selectedIds.value = [];
    void load();
}

watch(() => filters.search, () => {
    clearSearchTimer();
    searchTimer = setTimeout(applyFilters, 350);
});

watch(() => [filters.status, filters.email_verified], applyFilters);

onBeforeUnmount(() => {
    clearSearchTimer();
    latestRequest++;
    activeController?.abort();
});

onMounted(async () => {
    try { capabilities.value = await getCapabilities(); } catch { capabilities.value = null; }
    await load();
});

function search(): void {
    applyFilters();
}

async function exportFilteredUsers(): Promise<void> {
    exporting.value = true;
    try {
        const blob = await exportUsers({
            search: filters.search.trim() || undefined,
            status: filters.status || undefined,
            email_verified: filters.email_verified || undefined,
            sort_by: sort.by,
            sort_direction: sort.direction,
        });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'users.csv';
        link.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
        toast.value = 'Filtered users CSV downloaded.';
        toastVariant.value = 'success';
    } catch (exception) {
        toast.value = userApiErrorMessage(exception);
        toastVariant.value = 'error';
    } finally {
        exporting.value = false;
    }
}

function importCompleted(result: UserImportResult): void {
    toast.value = `Import complete: ${result.imported} imported, ${result.skipped} skipped, ${result.failed} failed.`;
    toastVariant.value = result.failed > 0 ? 'error' : 'success';
    void load(true);
}

function clearFilters(): void {
    filters.search = '';
    filters.status = '';
    filters.email_verified = '';
    applyFilters();
}

function pageChanged(page: number): void {
    meta.value.current_page = page;
    selectedIds.value = [];
    void load();
}

function pageSizeChanged(size: number): void {
    if (size === meta.value.per_page) return;
    meta.value.per_page = size;
    meta.value.current_page = 1;
    void load();
}

function sortChanged(key: string, direction: 'asc' | 'desc'): void {
    if (!columns.some((column) => column.key === key && column.sortable)) return;
    sort.by = key;
    sort.direction = direction;
    meta.value.current_page = 1;
    void load();
}

function ask(operation: string, id?: string | number): void {
    pending.value = { operation, id };
    confirmOpen.value = true;
}

const confirmMessage = () => {
    if (!pending.value) return '';
    const bulk = pending.value.operation.startsWith('bulk:');
    const operation = pending.value.operation.replace('bulk:', '');
    const subject = bulk ? `${selectedIds.value.length} selected users` : 'this user';
    const wording: Record<string, string> = { activate: 'activate', deactivate: 'deactivate', trash: 'move to trash' };
    return `Are you sure you want to ${wording[operation] ?? operation} ${subject}?`;
};

async function confirmAction(): Promise<void> {
    if (!pending.value) return;
    loading.value = true;
    const operation = pending.value.operation.replace('bulk:', '') as BulkUserOperation;
    try {
        if (pending.value.operation.startsWith('bulk:')) {
            const count = await bulkUsers(operation, selectedIds.value);
            toast.value = `${count} users ${operation === 'trash' ? 'moved to trash' : `${operation}d`}.`;
            toastVariant.value = 'success';
            selectedIds.value = [];
        } else if (pending.value.id !== undefined && operation === 'trash') {
            await trashUser(pending.value.id);
            toast.value = 'User moved to trash.';
            toastVariant.value = 'success';
        } else if (pending.value.id !== undefined) {
            await updateUserStatus(pending.value.id, operation === 'activate');
            toast.value = `User ${operation}d.`;
            toastVariant.value = 'success';
        }
        confirmOpen.value = false;
        pending.value = null;
        await load(true);
    } catch (exception) {
        toast.value = userApiErrorMessage(exception);
        toastVariant.value = 'error';
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <section class="grid gap-5">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div><h2 class="text-xl font-semibold text-slate-900">Users</h2><p class="mt-1 text-sm text-slate-600">Search, review, and manage host application accounts.</p></div>
            <div class="flex flex-wrap items-center gap-2">
                <CoreButton variant="secondary" :disabled="exporting || !capabilities?.export" :loading="exporting" :aria-describedby="!capabilities?.export ? 'user-csv-permission-note' : undefined" :title="!capabilities?.export ? 'Requires export-users authorization' : 'Export filtered users'" @click="exportFilteredUsers">Export Users</CoreButton>
                <CoreButton variant="secondary" :disabled="!capabilities?.import || !capabilities?.create" :aria-describedby="!capabilities?.import || !capabilities?.create ? 'user-csv-permission-note' : undefined" :title="!capabilities?.import || !capabilities?.create ? 'Requires import-users and create-users authorization' : 'Import users from CSV'" @click="importOpen = true">Import Users</CoreButton>
                <RouterLink v-if="capabilities?.create" class="mc-button mc-button--primary" to="/app/users/create">Create User</RouterLink>
            </div>
        </div>
        <p v-if="!capabilities || !capabilities.export || !capabilities.import || !capabilities.create" id="user-csv-permission-note" class="-mt-2 text-sm text-slate-600" role="note">
            <span v-if="!capabilities">Import/export access could not be verified. Retry after checking your session.</span>
            <span v-else-if="!capabilities.export">Export requires the <code>export-users</code> permission. </span>
            <span v-if="capabilities && (!capabilities.import || !capabilities.create)">Import requires the <code>import-users</code> permission and <code>create-users</code> authorization.</span>
        </p>
        <form class="grid grid-cols-1 items-end gap-4 rounded-lg border border-slate-200 bg-white p-4 md:grid-cols-4" @submit.prevent="search">
            <CoreField v-slot="{ id }" label="Search users"><CoreTextInput :id="id" v-model="filters.search" placeholder="Name or email" /></CoreField>
            <CoreField v-slot="{ id }" label="Account status">
                <select :id="id" v-model="filters.status" class="mc-control"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
            </CoreField>
            <CoreField v-slot="{ id }" label="Email verification">
                <select :id="id" v-model="filters.email_verified" class="mc-control"><option value="">All users</option><option value="verified">Verified</option><option value="unverified">Unverified</option></select>
            </CoreField>
        </form>
        <div v-if="selectedIds.length && capabilities?.status" class="flex flex-wrap items-center gap-2 rounded-lg bg-blue-50 p-3" role="group" aria-label="Bulk user actions">
            <span class="mr-2 text-sm text-blue-900">{{ selectedIds.length }} selected</span>
            <CoreButton variant="secondary" :loading="loading" @click="ask('bulk:activate')">Activate</CoreButton>
            <CoreButton variant="secondary" :loading="loading" @click="ask('bulk:deactivate')">Deactivate</CoreButton>
            <CoreButton v-if="capabilities.delete" variant="danger" :loading="loading" @click="ask('bulk:trash')">Move to trash</CoreButton>
        </div>
        <CoreDataTable
            v-model:selected-ids="selectedIds"
            v-model:visible-column-keys="visibleColumnKeys"
            :columns="columns"
            :rows="users"
            :page="meta.current_page"
            :last-page="meta.last_page"
            :total="meta.total"
            :per-page="meta.per_page"
            :loading="loading"
            :error="error"
            :sort-by="sort.by"
            :sort-direction="sort.direction"
            preference-key="master-core.users"
            :active-filter-count="activeFilterCount()"
            @update:page="pageChanged"
            @update:per-page="pageSizeChanged"
            @update:sort="sortChanged"
            @reset-filters="clearFilters"
        >
            <template #error>
                <div class="mc-table__state mc-table__state--error" role="alert">
                    <p>{{ error }}</p>
                    <CoreButton variant="secondary" :loading="loading" @click="load(true)">Retry</CoreButton>
                </div>
            </template>
            <template #cell-email_verified_at="{ row }">
                <span :class="row.email_verified_at ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'" class="rounded-full px-2.5 py-1 text-xs font-medium">{{ row.email_verified_at ? 'Verified' : 'Unverified' }}</span>
            </template>
            <template #cell-is_active="{ row }">
                <span :class="row.is_active ? 'bg-green-100 text-green-800' : 'bg-slate-200 text-slate-700'" class="rounded-full px-2.5 py-1 text-xs font-medium">{{ row.is_active ? 'Active' : 'Inactive' }}</span>
            </template>
            <template #cell-created_at="{ row }">{{ row.created_at ? new Date(row.created_at).toLocaleDateString() : '—' }}</template>
            <template #actions="{ row }">
                <div class="mc-user-actions">
                    <RouterLink class="mc-icon-action" :to="`/app/users/${row.id}`" :aria-label="`View ${row.name}`" data-tooltip="View" title="View">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                    </RouterLink>
                    <RouterLink v-if="capabilities?.update" class="mc-icon-action" :to="`/app/users/${row.id}/edit`" :aria-label="`Edit ${row.name}`" data-tooltip="Edit" title="Edit">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5 4 4M4 20l4-.8L19 8a2.1 2.1 0 0 0-3-3L5 16l-1 4Z"/></svg>
                    </RouterLink>
                    <button v-if="capabilities?.status" class="mc-icon-action" type="button" :aria-label="`${row.is_active ? 'Deactivate' : 'Activate'} ${row.name}`" :data-tooltip="row.is_active ? 'Deactivate' : 'Activate'" :title="row.is_active ? 'Deactivate' : 'Activate'" @click="ask(row.is_active ? 'deactivate' : 'activate', row.id)">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v9"/><path d="M6.1 5.9a8 8 0 1 0 11.8 0"/></svg>
                    </button>
                    <button v-if="capabilities?.delete" class="mc-icon-action mc-icon-action--danger" type="button" :aria-label="`Move ${row.name} to trash`" data-tooltip="Move to trash" title="Move to trash" @click="ask('trash', row.id)">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6m4-6v6M5 7l1 14h12l1-14M9 7V4h6v3"/></svg>
                    </button>
                </div>
            </template>
        </CoreDataTable>
        <CoreToast v-if="toast" :message="toast" :variant="toastVariant" @dismiss="toast = ''" />
        <UserImportDialog :open="importOpen" @close="importOpen = false" @complete="importCompleted" />
        <CoreConfirmDialog
            :open="confirmOpen"
            title="Confirm user action"
            :message="confirmMessage()"
            :confirm-label="pending?.operation.includes('trash') || pending?.operation.includes('deactivate') ? 'Confirm' : 'Continue'"
            :destructive="Boolean(pending?.operation.includes('trash') || pending?.operation.includes('deactivate'))"
            @cancel="confirmOpen = false"
            @confirm="confirmAction"
        />
    </section>
</template>
