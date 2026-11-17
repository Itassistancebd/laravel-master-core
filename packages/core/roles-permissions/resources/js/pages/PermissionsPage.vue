<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { CoreButton, CoreDataTable, CoreField, CoreFormSection, CoreTextInput, CoreToast } from '@itassistance-bd/ui';
import { availablePermissions, getCurrentPermissions, listPermissions, materializePermission, rolesPermissionsErrorMessage, updatePermission } from '../api';
import type { PermissionRecord, RegisteredPermission } from '../types';

const rows = ref<PermissionRecord[]>([]);
const available = ref<RegisteredPermission[]>([]);
const groupOptions = ref<string[]>([]);
const selected = ref('');
const loading = ref(false);
const error = ref('');
const toast = ref('');
const search = ref('');
const group = ref('');
const page = ref(1);
const perPage = ref(25);
const lastPage = ref(1);
const total = ref(0);
const sortBy = ref('group_name');
const sortDirection = ref<'asc' | 'desc'>('asc');
const selectedIds = ref<Array<string | number>>([]);
const editing = ref<PermissionRecord | null>(null);
const editGroup = ref('');
const editDescription = ref('');
const canManagePermissions = ref(false);
const columns = [
    { key: 'name', label: 'Ability', sortable: true },
    { key: 'group_name', label: 'Group', sortable: true },
    { key: 'description', label: 'Description' },
    { key: 'roles_count', label: 'Roles' },
    { key: 'is_registered', label: 'Registry' },
];

async function load(): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
        const allowed = await getCurrentPermissions();
        canManagePermissions.value = allowed.includes('manage-permissions');
        if (!canManagePermissions.value) editing.value = null;
        const [results, registry] = await Promise.all([
            listPermissions({ search: search.value, group: group.value || undefined, page: page.value, per_page: perPage.value, sort_by: sortBy.value, sort_direction: sortDirection.value }),
            canManagePermissions.value ? availablePermissions() : Promise.resolve([]),
        ]);
        rows.value = results.permissions;
        lastPage.value = results.meta.last_page;
        total.value = results.meta.total;
        groupOptions.value = results.groups;
        available.value = registry;
        if (!available.value.some((item) => item.name === selected.value)) selected.value = available.value[0]?.name ?? '';
    } catch (exception) { error.value = rolesPermissionsErrorMessage(exception); }
    finally { loading.value = false; }
}

onMounted(load);

async function updatePage(value: number): Promise<void> { page.value = value; await load(); }
async function updatePerPage(value: number): Promise<void> { perPage.value = value; page.value = 1; await load(); }
async function updateSort(key: string, direction: 'asc' | 'desc'): Promise<void> { sortBy.value = key; sortDirection.value = direction; await load(); }

async function addRegistered(): Promise<void> {
    // The selectable list comes from the code registry; free-form ability names are never submitted.
    if (!selected.value) return;
    loading.value = true;
    try {
        await materializePermission(selected.value);
        toast.value = `Registered permission “${selected.value}” is available for role grants.`;
        await load();
    } catch (exception) { error.value = rolesPermissionsErrorMessage(exception); }
    finally { loading.value = false; }
}

function editMetadata(permission: PermissionRecord): void {
    editing.value = permission;
    editGroup.value = permission.group_name;
    editDescription.value = permission.description ?? '';
}

async function saveMetadata(): Promise<void> {
    if (!editing.value) return;
    loading.value = true;
    try {
        await updatePermission(editing.value.id, editGroup.value.trim(), editDescription.value.trim());
        toast.value = 'Permission display details updated.';
        editing.value = null;
        await load();
    } catch (exception) { error.value = rolesPermissionsErrorMessage(exception); }
    finally { loading.value = false; }
}
</script>

<template>
    <main class="grid gap-6">
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3"><h1 class="text-xl font-semibold text-slate-900">Permissions</h1><RouterLink class="text-sm text-blue-700 hover:underline" to="/app/roles">Roles</RouterLink></div>
            <p class="mt-1 text-sm text-slate-600">Only code-registered abilities can be materialized and granted.</p>
            <p class="mt-1 text-xs text-slate-500">The catalog below contains materialized database permissions; the selector lists registered abilities not yet materialized.</p>
            <div v-if="canManagePermissions" class="mt-5 flex flex-wrap items-end gap-3">
                <CoreField id="registered-ability" class="min-w-64" label="Registered ability">
                    <select id="registered-ability" v-model="selected" class="mc-input w-full" :disabled="available.length === 0">
                        <option v-for="permission in available" :key="permission.name" :value="permission.name">{{ permission.name }} — {{ permission.group_name }}</option>
                    </select>
                </CoreField>
                <CoreButton :loading="loading" :disabled="!selected" @click="addRegistered">Add to permission catalog</CoreButton>
            </div>
            <p v-if="canManagePermissions && available.length === 0" class="mt-3 text-sm text-slate-600">No unmaterialized registered abilities remain.</p>
        </section>
        <section v-if="editing" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">Edit permission display details</h2>
            <p class="mt-1 text-sm text-slate-600">The executable ability name stays owned by its code registry.</p>
            <form class="mt-4" @submit.prevent="saveMetadata">
                <CoreFormSection number="1" title="Display details" description="Only the catalog grouping and explanatory text can be changed." :columns="2">
                    <CoreField label="Group"><CoreTextInput id="permission-group" v-model="editGroup" required maxlength="100" /></CoreField>
                    <CoreField label="Description"><CoreTextInput id="permission-description" v-model="editDescription" maxlength="255" /></CoreField>
                    <div class="flex flex-wrap gap-2 sm:col-span-2"><CoreButton type="submit" :loading="loading">Save details</CoreButton><CoreButton type="button" variant="secondary" @click="editing = null">Cancel</CoreButton></div>
                </CoreFormSection>
            </form>
        </section>
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">Catalog</h2>
            <div class="my-4 flex flex-wrap items-end gap-3">
                <CoreField label="Search"><CoreTextInput id="permission-search" v-model="search" placeholder="Search abilities" @keyup.enter="page = 1; load()" /></CoreField>
                <CoreField label="Group"><select v-model="group" class="mc-input" @change="page = 1; load()"><option value="">All groups</option><option v-for="item in groupOptions" :key="item" :value="item">{{ item }}</option></select></CoreField>
                <CoreButton variant="secondary" :loading="loading" @click="page = 1; load()">Search</CoreButton>
            </div>
            <CoreDataTable :columns="columns" :rows="rows" :page="page" :last-page="lastPage" :total="total" :per-page="perPage" :selected-ids="selectedIds" :loading="loading" :error="error" :sort-by="sortBy" :sort-direction="sortDirection" preference-key="master-core.permissions" :export-enabled="true" export-filename="master-core-permissions.csv" :active-filter-count="Number(Boolean(search.trim())) + Number(Boolean(group))" empty-message="No permission catalog records match your filters." @update:page="updatePage" @update:per-page="updatePerPage" @update:sort="updateSort" @update:selected-ids="selectedIds = $event" @reset-filters="search = ''; group = ''; page = 1; selectedIds = []; load()">
                <template #cell-is_registered="{ row }">{{ row.is_registered ? 'Registered' : 'Not registered' }}</template>
                <template #actions="{ row }"><div v-if="canManagePermissions" class="flex gap-2"><CoreButton variant="secondary" @click="editMetadata(row)">Edit details</CoreButton></div></template>
            </CoreDataTable>
        </section>
        <CoreToast v-if="toast" :message="toast" @dismiss="toast = ''" />
    </main>
</template>
