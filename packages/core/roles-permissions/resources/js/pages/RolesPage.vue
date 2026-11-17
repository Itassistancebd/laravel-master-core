<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { CoreButton, CoreConfirmDialog, CoreDataTable, CoreField, CoreFormSection, CoreTextInput, CoreToast } from '@itassistance-bd/ui';
import { bulkDeleteRoles, createRole, deleteRole, getCurrentPermissions, getRole, listPermissions, listRoles, rolesPermissionsErrorMessage, rolesPermissionsValidationErrors, syncRolePermissions, updateRole } from '../api';
import type { PermissionRecord, RoleRecord } from '../types';

const roles = ref<RoleRecord[]>([]);
const permissions = ref<PermissionRecord[]>([]);
const name = ref('');
const selectedRole = ref<RoleRecord | null>(null);
const viewMode = ref(false);
const selectedPermissions = ref<number[]>([]);
const loading = ref(false);
const error = ref('');
const errors = ref<Record<string, string[]>>({});
const toast = ref('');
const deleteTarget = ref<RoleRecord | null>(null);
const bulkDeleteOpen = ref(false);
const search = ref('');
const page = ref(1);
const perPage = ref(25);
const lastPage = ref(1);
const total = ref(0);
const sortBy = ref('name');
const sortDirection = ref<'asc' | 'desc'>('asc');
const selectedIds = ref<Array<string | number>>([]);
const capabilities = ref({ create: false, edit: false, delete: false, viewPermissions: false });
const canEditRolePermissions = () => capabilities.value.edit && capabilities.value.viewPermissions;
const columns = [
    { key: 'name', label: 'Role', sortable: true },
    { key: 'role_key', label: 'Immutable key' },
    { key: 'permissions_count', label: 'Permissions', sortable: true },
    { key: 'members_count', label: 'Members', sortable: true },
];

async function load(): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
        const allowed = await getCurrentPermissions();
        capabilities.value = {
            create: allowed.includes('create-roles'),
            edit: allowed.includes('edit-roles'),
            delete: allowed.includes('delete-roles'),
            viewPermissions: allowed.includes('view-permissions'),
        };
        const [rolePage, permissionPage] = await Promise.all([
            listRoles({ search: search.value, page: page.value, per_page: perPage.value, sort_by: sortBy.value, sort_direction: sortDirection.value }),
            canEditRolePermissions() ? listPermissions({ per_page: 100 }) : Promise.resolve({ permissions: [], meta: { current_page: 1, per_page: 100, total: 0, last_page: 1 }, groups: [] }),
        ]);
        roles.value = rolePage.roles;
        lastPage.value = rolePage.meta.last_page;
        total.value = rolePage.meta.total;
        permissions.value = permissionPage.permissions;
    } catch (exception) {
        error.value = rolesPermissionsErrorMessage(exception);
    } finally {
        loading.value = false;
    }
}

onMounted(load);

async function updatePage(value: number): Promise<void> { page.value = value; await load(); }
async function updatePerPage(value: number): Promise<void> { perPage.value = value; page.value = 1; await load(); }
async function updateSort(key: string, direction: 'asc' | 'desc'): Promise<void> { sortBy.value = key; sortDirection.value = direction; await load(); }

async function saveRole(): Promise<void> {
    // The API rechecks every grant against the operator's effective permissions before persisting it.
    error.value = '';
    errors.value = {};
    loading.value = true;
    try {
        if (selectedRole.value) {
            const updated = await updateRole(selectedRole.value.id, name.value.trim());
            if (canEditRolePermissions()) await syncRolePermissions(updated.id, selectedPermissions.value);
            toast.value = 'Role updated.';
        } else {
            const created = await createRole(name.value.trim());
            toast.value = 'Role created. Assign registered permissions when ready.';
            selectedRole.value = created;
        }
        name.value = '';
        selectedRole.value = null;
        viewMode.value = false;
        selectedPermissions.value = [];
        await load();
    } catch (exception) {
        error.value = rolesPermissionsErrorMessage(exception);
        errors.value = rolesPermissionsValidationErrors(exception);
    } finally {
        loading.value = false;
    }
}

async function openRole(role: RoleRecord, readOnly: boolean): Promise<void> {
    loading.value = true;
    try {
        const detail = await getRole(role.id);
        selectedRole.value = detail;
        viewMode.value = readOnly;
        name.value = detail.name;
        selectedPermissions.value = [...(detail.permission_ids ?? [])];
    } catch (exception) {
        error.value = rolesPermissionsErrorMessage(exception);
    } finally {
        loading.value = false;
    }
}

async function removeRole(): Promise<void> {
    if (!deleteTarget.value) return;
    loading.value = true;
    try {
        await deleteRole(deleteTarget.value.id);
        toast.value = 'Role deleted.';
        deleteTarget.value = null;
        await load();
    } catch (exception) {
        error.value = rolesPermissionsErrorMessage(exception);
    } finally {
        loading.value = false;
    }
}

async function removeSelectedRoles(): Promise<void> {
    const ids = selectedIds.value.map(Number);
    if (ids.length === 0) return;
    loading.value = true;
    try {
        const deleted = await bulkDeleteRoles(ids);
        toast.value = `${deleted} role${deleted === 1 ? '' : 's'} deleted.`;
        selectedIds.value = [];
        bulkDeleteOpen.value = false;
        await load();
    } catch (exception) { error.value = rolesPermissionsErrorMessage(exception); }
    finally { loading.value = false; }
}
</script>

<template>
    <main class="grid gap-6 lg:grid-cols-[minmax(18rem,1fr)_2fr]">
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3"><h1 class="text-xl font-semibold text-slate-900">Roles</h1><RouterLink class="text-sm text-blue-700 hover:underline" to="/app/permissions">Permission catalog</RouterLink></div>
            <p class="mt-1 text-sm text-slate-600">Create named access groups and grant only registered permissions.</p>
            <form v-if="selectedRole ? (viewMode || capabilities.edit) : capabilities.create" class="mt-5 grid gap-4" @submit.prevent="saveRole">
                <CoreFormSection number="1" title="Role details" description="Set the role name and, when editing, its registered access permissions." :columns="1">
                    <CoreField id="role-name" label="Role name" :error="errors.name?.[0]">
                        <CoreTextInput id="role-name" v-model="name" name="role-name" :disabled="viewMode" required maxlength="120" autocomplete="off" />
                    </CoreField>
                    <fieldset v-if="selectedRole && canEditRolePermissions()" class="max-h-72 space-y-2 overflow-auto rounded border border-slate-200 p-3">
                        <legend class="px-1 text-sm font-medium text-slate-700">Registered permissions</legend>
                        <label v-for="permission in permissions" :key="permission.id" class="flex gap-2 text-sm text-slate-700">
                            <input v-model="selectedPermissions" type="checkbox" :value="permission.id" :disabled="viewMode" class="mt-1 rounded border-slate-300">
                            <span><span class="font-medium">{{ permission.name }}</span><span class="block text-xs text-slate-500">{{ permission.description }}</span></span>
                        </label>
                        <p v-if="permissions.length === 0" class="text-sm text-slate-500">No permissions have been materialized.</p>
                    </fieldset>
                </CoreFormSection>
                <div class="flex gap-2">
                    <CoreButton v-if="!viewMode && (selectedRole ? capabilities.edit : capabilities.create)" type="submit" :loading="loading">{{ selectedRole ? 'Save role' : 'Create role' }}</CoreButton>
                    <CoreButton v-if="selectedRole" type="button" variant="secondary" @click="selectedRole = null; viewMode = false; name = ''; selectedPermissions = []">Close</CoreButton>
                </div>
            </form>
        </section>
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-slate-900">Existing roles</h2>
            <form class="my-4 flex flex-wrap gap-2" @submit.prevent="page = 1; load()">
                <CoreTextInput id="role-search" v-model="search" type="text" aria-label="Search roles" placeholder="Search role names" />
                <CoreButton type="submit" variant="secondary" :loading="loading">Search</CoreButton>
            </form>
            <div v-if="selectedIds.length" class="mb-3 flex flex-wrap items-center gap-3" aria-live="polite">
                <span class="text-sm text-slate-600">{{ selectedIds.length }} roles selected</span>
                <CoreButton v-if="capabilities.delete" variant="danger" :loading="loading" @click="bulkDeleteOpen = true">Delete selected</CoreButton>
            </div>
            <p v-if="error" class="mt-3 rounded bg-red-50 p-3 text-sm text-red-800" role="alert">{{ error }}</p>
            <CoreDataTable
                v-else
                :columns="columns"
                :rows="roles"
                :page="page"
                :last-page="lastPage"
                :total="total"
                :per-page="perPage"
                :selected-ids="selectedIds"
                :loading="loading"
                :sort-by="sortBy"
                :sort-direction="sortDirection"
                preference-key="master-core.roles"
                :export-enabled="true"
                export-filename="master-core-roles.csv"
                :active-filter-count="Number(Boolean(search.trim()))"
                empty-message="No roles match your search."
                @update:page="updatePage"
                @update:per-page="updatePerPage"
                @update:sort="updateSort"
                @update:selected-ids="selectedIds = $event"
                @reset-filters="search = ''; page = 1; selectedIds = []; load()"
            >
                <template #actions="{ row }">
                    <div class="flex flex-wrap gap-2">
                        <CoreButton variant="secondary" @click="openRole(row, true)">View</CoreButton>
                        <CoreButton v-if="capabilities.edit && !row.is_system && row.role_key !== 'core-super-admin'" variant="secondary" @click="openRole(row, false)">Edit</CoreButton>
                        <CoreButton v-if="capabilities.delete && !row.is_system" variant="danger" @click="deleteTarget = row">Delete</CoreButton>
                    </div>
                </template>
            </CoreDataTable>
        </section>
        <CoreToast v-if="toast" :message="toast" @dismiss="toast = ''" />
        <CoreConfirmDialog :open="deleteTarget !== null" title="Delete role" message="Only unassigned roles without permissions can be deleted." confirm-label="Delete role" :destructive="true" @cancel="deleteTarget = null" @confirm="removeRole" />
        <CoreConfirmDialog :open="bulkDeleteOpen" title="Delete selected roles" :message="`Delete ${selectedIds.length} selected roles? All selected roles must be unassigned and have no permissions.`" confirm-label="Delete selected" :destructive="true" @cancel="bulkDeleteOpen = false" @confirm="removeSelectedRoles" />
    </main>
</template>
