<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { CoreButton, CoreConfirmDialog, CoreDataTable, CoreField, CoreTextInput, CoreToast } from '@itassistance-bd/ui';
import type { CoreDataTableColumn } from '@itassistance-bd/ui';
import { bulkUsers, getCapabilities, listUsers, permanentlyDeleteUser, userApiErrorMessage } from '../api';
import type { ManagedUser, UserCapabilities, UserPageMeta } from '../types';

const columns: CoreDataTableColumn[] = [
    { key: 'name', label: 'Name', sortable: true, required: true },
    { key: 'email', label: 'Email', sortable: true },
    { key: 'created_at', label: 'Created', sortable: true },
    { key: 'deleted_at', label: 'Deleted', sortable: true },
];
const router = useRouter();
const users = ref<ManagedUser[]>([]);
const capabilities = ref<UserCapabilities | null>(null);
const selectedIds = ref<Array<string | number>>([]);
const visibleColumnKeys = ref(columns.map((column) => column.key));
const filters = reactive({ search: '' });
const sort = reactive({ by: 'created_at', direction: 'desc' as 'asc' | 'desc' });
const meta = ref<UserPageMeta>({ current_page: 1, per_page: 25, total: 0, last_page: 1, from: null, to: null });
const loading = ref(false);
const error = ref('');
const toast = ref('');
const pendingRestore = ref<string | number | null>(null);
const permanentUser = ref<ManagedUser | null>(null);
const confirmation = ref('');
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let activeController: AbortController | null = null;
let latestRequest = 0;
let lastRequestKey = '';

function requestKey(): string {
    return JSON.stringify({ search: filters.search.trim(), sort_by: sort.by, sort_direction: sort.direction, page: meta.value.current_page, per_page: meta.value.per_page });
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
            sort_by: sort.by,
            sort_direction: sort.direction,
            page: meta.value.current_page,
            per_page: meta.value.per_page,
        }, true, controller.signal);
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

function applySearch(): void {
    clearSearchTimer();
    selectedIds.value = [];
    meta.value.current_page = 1;
    void load();
}

watch(() => filters.search, () => {
    clearSearchTimer();
    searchTimer = setTimeout(applySearch, 350);
});

onBeforeUnmount(() => { clearSearchTimer(); latestRequest++; activeController?.abort(); });

onMounted(async () => {
    try { capabilities.value = await getCapabilities(); } catch { capabilities.value = null; }
    await load();
});

function changePage(page: number): void { meta.value.current_page = page; selectedIds.value = []; void load(); }
function changeSize(size: number): void { if (size === meta.value.per_page) return; meta.value.per_page = size; meta.value.current_page = 1; void load(); }
function changeSort(key: string, direction: 'asc' | 'desc'): void { if (!columns.some((column) => column.key === key && column.sortable)) return; sort.by = key; sort.direction = direction; meta.value.current_page = 1; selectedIds.value = []; void load(); }
function search(): void { applySearch(); }
function clearFilters(): void { filters.search = ''; applySearch(); }

async function restoreSelected(ids: Array<string | number>): Promise<void> {
    loading.value = true;
    try {
        const count = await bulkUsers('restore', ids);
        toast.value = `${count} user${count === 1 ? '' : 's'} restored.`;
        selectedIds.value = [];
        await load(true);
    } catch (exception) {
        error.value = userApiErrorMessage(exception);
    } finally {
        loading.value = false;
    }
}

async function restoreOne(): Promise<void> {
    if (pendingRestore.value === null) return;
    await restoreSelected([pendingRestore.value]);
    pendingRestore.value = null;
}

async function forceDelete(): Promise<void> {
    if (!permanentUser.value) return;
    loading.value = true;
    error.value = '';
    try {
        await permanentlyDeleteUser(permanentUser.value.id, confirmation.value);
        toast.value = 'User permanently deleted.';
        permanentUser.value = null;
        confirmation.value = '';
        await load(true);
    } catch (exception) {
        if (typeof exception === 'object' && exception !== null && 'response' in exception && (exception as { response?: { status?: number } }).response?.status === 423) {
            await router.push({ path: '/user/confirm-password', query: { redirect: '/app/users/trash' } });
            return;
        }
        error.value = userApiErrorMessage(exception);
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <section class="grid gap-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div><h2 class="text-xl font-semibold text-slate-900">Trash</h2><p class="mt-1 text-sm text-slate-600">Restore accounts or permanently remove one after confirmation.</p></div>
            <RouterLink class="text-sm text-blue-700 hover:underline" to="/app/users">Back to users</RouterLink>
        </div>
        <form class="flex flex-wrap items-end gap-4 rounded-lg border border-slate-200 bg-white p-4" @submit.prevent="search">
            <CoreField v-slot="{ id }" class="min-w-64 flex-1" label="Search trashed users"><CoreTextInput :id="id" v-model="filters.search" placeholder="Name or email" /></CoreField>
        </form>
        <div v-if="selectedIds.length && capabilities?.restore" class="flex flex-wrap items-center gap-3 rounded-lg bg-blue-50 p-3">
            <span class="text-sm text-blue-900">{{ selectedIds.length }} selected</span>
            <CoreButton variant="secondary" :loading="loading" @click="restoreSelected(selectedIds)">Restore selected</CoreButton>
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
            preference-key="master-core.users-trash"
            :active-filter-count="Number(Boolean(filters.search.trim()))"
            empty-message="Trash is empty."
            @update:page="changePage"
            @update:per-page="changeSize"
            @update:sort="changeSort"
            @reset-filters="clearFilters"
        >
            <template #error>
                <div class="mc-table__state mc-table__state--error" role="alert">
                    <p>{{ error }}</p>
                    <CoreButton variant="secondary" :loading="loading" @click="load(true)">Retry</CoreButton>
                </div>
            </template>
            <template #cell-created_at="{ row }">{{ row.created_at ? new Date(row.created_at).toLocaleDateString() : '—' }}</template>
            <template #cell-deleted_at="{ row }">{{ row.deleted_at ? new Date(row.deleted_at).toLocaleDateString() : '—' }}</template>
            <template #actions="{ row }">
                <div class="mc-user-actions">
                    <button v-if="capabilities?.restore" class="mc-icon-action" type="button" :aria-label="`Restore ${row.name}`" data-tooltip="Restore" title="Restore" @click="pendingRestore = row.id">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11a9 9 0 1 1 2.6 6.4M3 4v7h7"/><path d="M12 7v5l3 2"/></svg>
                    </button>
                    <button v-if="capabilities?.force_delete" class="text-red-700 hover:underline" type="button" @click="permanentUser = row; confirmation = ''">Delete permanently</button>
                </div>
            </template>
        </CoreDataTable>
        <CoreToast v-if="toast" :message="toast" @dismiss="toast = ''" />
        <CoreConfirmDialog :open="pendingRestore !== null" title="Restore this user?" message="The account will return to the active user list with its previous active or inactive status." confirm-label="Restore" @cancel="pendingRestore = null" @confirm="restoreOne" />
        <Teleport to="body">
            <div v-if="permanentUser" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/50 p-4" @click.self="permanentUser = null">
                <section class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="force-delete-title">
                    <h3 id="force-delete-title" class="text-lg font-semibold text-slate-900">Permanently delete this user?</h3>
                    <p class="mt-2 text-sm text-slate-600">This cannot be undone. Enter <strong>{{ permanentUser.email }}</strong> to confirm. Recent password confirmation is also required.</p>
                    <div class="mt-5"><CoreField v-slot="{ id }" label="Confirm email address" required><CoreTextInput :id="id" v-model="confirmation" autocomplete="off" /></CoreField></div>
                    <p v-if="error" class="mt-3 text-sm text-red-800" role="alert">{{ error }}</p>
                    <div class="mt-6 flex justify-end gap-3"><CoreButton variant="secondary" @click="permanentUser = null">Cancel</CoreButton><CoreButton variant="danger" :loading="loading" :disabled="confirmation !== permanentUser.email" @click="forceDelete">Delete permanently</CoreButton></div>
                </section>
            </div>
        </Teleport>
    </section>
</template>
