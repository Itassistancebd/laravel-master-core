<script setup lang="ts">
import axios from 'axios';
import { onMounted, ref } from 'vue';
import { RegisteredCrudPage } from '@itassistance-bd/crud-engine';
import type { CrudCapabilities, CrudPage, CrudPageDescriptor, CrudQuery, CrudTransport } from '@itassistance-bd/crud-engine';
import { getCurrentPermissions } from '@itassistance-bd/roles-permissions';

interface Category { id: number; name: string; slug: string; description: string | null; status: 'active' | 'inactive'; created_at: string; updated_at: string }
const mountPoint = document.querySelector<HTMLElement>('#master-core-auth, #master-core-settings');
const categoriesApiBase = mountPoint?.dataset.categoriesApiBase ?? (mountPoint?.dataset.dashboardApiBase ?? '/api/v1/core/dashboard').replace(/\/core\/dashboard$/, '/core/categories');
const api = axios.create({ baseURL: categoriesApiBase, withCredentials: true, withXSRFToken: true });
const capabilities = ref<CrudCapabilities>({});
const descriptor: CrudPageDescriptor<Category> = {
    title: 'Categories', createLabel: 'Add Category', description: 'Manage category names, slugs, descriptions and status.', rowKey: 'id',
    messages: { created: 'Category created.', updated: 'Category updated.', deleted: 'Category deleted.', deleteConfirmation: 'Delete this category?' },
    columns: [
        { key: 'name', label: 'Name', sortable: true }, { key: 'slug', label: 'Slug', sortable: true },
        { key: 'description', label: 'Description' }, { key: 'status', label: 'Status', sortable: true },
        { key: 'created_at', label: 'Created At', sortable: true }, { key: 'updated_at', label: 'Updated At' },
    ],
    fields: [
        { key: 'name', label: 'Name', type: 'text', required: true },
        { key: 'slug', label: 'Slug', type: 'text', help: 'Leave blank when creating to generate it from the name.' },
        { key: 'description', label: 'Description', type: 'text' },
        { key: 'status', label: 'Status', type: 'select', required: true, options: [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }] },
    ],
    filters: [{ key: 'status', label: 'Status', options: [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }] }],
};
const transport: CrudTransport<Category> = {
    async list(query: CrudQuery): Promise<CrudPage<Category>> {
        const { data } = await api.get('', { params: { ...query, filters: query.filters } });
        return { items: data.data, ...data.meta };
    },
    async get(id) { return (await api.get(`/${id}`)).data.data; },
    async create(input) { return (await api.post('', input)).data.data; },
    async update(id, input) { return (await api.put(`/${id}`, input)).data.data; },
    async remove(id) { await api.delete(`/${id}`); },
};
onMounted(async () => {
    try {
        const permissions = new Set(await getCurrentPermissions());
        capabilities.value = { view: permissions.has('categories.view'), create: permissions.has('categories.create'), edit: permissions.has('categories.update'), delete: permissions.has('categories.delete') };
    } catch { capabilities.value = {}; }
});
</script>

<template>
    <nav aria-label="Breadcrumb" class="mb-4 text-sm text-slate-500"><span>Workspace</span><span aria-hidden="true"> / </span><span class="text-slate-900">Categories</span></nav>
    <RegisteredCrudPage :descriptor="descriptor" :transport="transport" :capabilities="capabilities" />
</template>
