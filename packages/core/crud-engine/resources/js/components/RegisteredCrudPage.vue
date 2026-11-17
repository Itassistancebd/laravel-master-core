<script setup lang="ts" generic="T extends { id: string | number }">
import { onMounted, reactive, ref } from 'vue';
import { CoreButton, CoreCheckbox, CoreConfirmDialog, CoreDataTable, CoreField, CoreFormSection, CoreMultiSelect, CoreSearchSelect, CoreTextInput, CoreToast } from '@itassistance-bd/ui';
import type { CoreDataTableColumn } from '@itassistance-bd/ui';
import type { CrudCapabilities, CrudField, CrudPageDescriptor, CrudTransport } from '../types';
import { useCrudResource } from '../useCrudResource';

const props = defineProps<{
    descriptor: CrudPageDescriptor<T>;
    transport: CrudTransport<T>;
    capabilities: CrudCapabilities;
}>();

const { rows, meta, query, loading, error, notice, noticeVariant, fieldErrors, selectedIds, load, search, setPage, setPerPage, setSort, setFilter, save: saveRecord, remove, removeSelected } = useCrudResource(props.transport, props.descriptor.columns.find((column) => column.sortable)?.key ?? 'id', props.descriptor.messages);
const searchText = ref('');
const editId = ref<string | number | undefined>();
const formOpen = ref(false);
const form = reactive<Record<string, unknown>>({});
const visibleColumns = ref(props.descriptor.columns.map((column) => column.key));
const confirmAction = ref<'delete' | 'bulk' | null>(null);
const detail = ref<T | null>(null);

function resetForm(record?: T): void {
    editId.value = record?.id;
    for (const field of props.descriptor.fields) {
        form[field.key] = record?.[field.key as keyof T] ?? (field.type === 'boolean' ? false : field.type === 'multiselect' ? [] : field.type === 'select' ? field.options[0]?.value ?? '' : '');
    }
    formOpen.value = true;
}

async function save(): Promise<void> {
    const values = Object.fromEntries(props.descriptor.fields.map((field) => [field.key, form[field.key]]));
    const result = await saveRecord(values, editId.value);
    if (result) formOpen.value = false;
}

async function runConfirmed(): Promise<void> {
    if (confirmAction.value === 'delete' && selectedIds.value.length === 1) {
        await remove(selectedIds.value[0]);
        selectedIds.value = [];
    } else if (confirmAction.value === 'bulk') {
        await removeSelected();
    }
    confirmAction.value = null;
}

function inputType(field: CrudField): 'text' | 'email' | 'number' {
    return field.type === 'email' || field.type === 'number' ? field.type : 'text';
}

onMounted(() => { void load(); });
</script>

<template>
    <section class="grid gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">{{ descriptor.title }}</h1>
                <p v-if="descriptor.description" class="mt-1 text-sm text-slate-600">{{ descriptor.description }}</p>
            </div>
            <CoreButton v-if="capabilities.create" variant="primary" @click="resetForm()">{{ descriptor.createLabel ?? 'Create' }}</CoreButton>
        </header>

        <form class="grid grid-cols-1 items-end gap-4 rounded-lg border border-slate-200 bg-white p-4 md:grid-cols-3" @submit.prevent="search(searchText)">
            <CoreField v-slot="{ id }" label="Search">
                <CoreTextInput :id="id" v-model="searchText" placeholder="Search records" />
            </CoreField>
            <CoreField v-for="filter in descriptor.filters ?? []" :key="filter.key" v-slot="{ id }" :label="filter.label">
                <select :id="id" class="mc-control" :value="String(query.filters[filter.key] ?? '')" @change="setFilter(filter.key, ($event.target as HTMLSelectElement).value)">
                    <option value="">All</option>
                    <option v-for="option in filter.options" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
            </CoreField>
            <CoreButton type="submit" variant="primary" :loading="loading">Search</CoreButton>
        </form>

        <div v-if="capabilities.bulkDelete && selectedIds.length" class="flex items-center gap-3 rounded-lg bg-blue-50 p-3" role="group" aria-label="Bulk actions">
            <span class="text-sm">{{ selectedIds.length }} selected</span>
            <CoreButton variant="danger" :loading="loading" @click="confirmAction = 'bulk'">Move selected to trash</CoreButton>
        </div>

        <form v-if="formOpen" class="grid gap-4 rounded-lg border border-slate-200 bg-white p-4" @submit.prevent="save">
            <CoreFormSection number="1" :title="editId === undefined ? 'Record details' : 'Edit details'" description="Complete the fields defined for this resource.">
                <CoreField v-for="field in descriptor.fields" :key="field.key" v-slot="{ id, describedBy }" :label="field.label" :help="field.help" :required="field.required" :error="fieldErrors[field.key]?.[0]">
                    <CoreCheckbox v-if="field.type === 'boolean'" :id="id" :model-value="Boolean(form[field.key])" :aria-describedby="describedBy" @update:model-value="form[field.key] = $event" />
                    <CoreSearchSelect v-else-if="field.type === 'select'" :id="id" :model-value="(form[field.key] as string | number) || null" :options="field.options" :described-by="describedBy" :invalid="Boolean(fieldErrors[field.key]?.length)" @update:model-value="form[field.key] = $event" />
                    <CoreMultiSelect v-else-if="field.type === 'multiselect'" :id="id" :model-value="(form[field.key] as Array<string | number>) ?? []" :options="field.options" :described-by="describedBy" :invalid="Boolean(fieldErrors[field.key]?.length)" @update:model-value="form[field.key] = $event" />
                    <CoreTextInput v-else :id="id" :type="inputType(field)" :model-value="String(form[field.key] ?? '')" :aria-describedby="describedBy" :invalid="Boolean(fieldErrors[field.key]?.length)" :required="field.required" @update:model-value="form[field.key] = $event" />
                </CoreField>
            </CoreFormSection>
            <div class="flex gap-2">
                <CoreButton type="submit" variant="primary" :loading="loading">Save</CoreButton>
                <CoreButton type="button" @click="formOpen = false">Cancel</CoreButton>
            </div>
        </form>

        <CoreDataTable
            v-model:selected-ids="selectedIds"
            v-model:visible-column-keys="visibleColumns"
            :columns="descriptor.columns"
            :rows="rows"
            :row-key="descriptor.rowKey ?? 'id'"
            :page="meta.current_page"
            :last-page="meta.last_page"
            :total="meta.total"
            :per-page="meta.per_page"
            :loading="loading"
            :error="error"
            :sort-by="query.sort_by"
            :sort-direction="query.sort_direction"
            :selectable="Boolean(capabilities.bulkDelete)"
            @update:page="setPage"
            @update:per-page="setPerPage"
            @update:sort="setSort"
        >
            <template #actions="{ row }">
                <div class="flex items-center gap-3">
                    <button v-if="capabilities.view && transport.get" type="button" class="text-slate-700 hover:underline" @click="transport.get(row.id).then((record) => detail = record).catch(() => error = 'The record could not be loaded.')">View</button>
                    <button v-if="capabilities.edit" type="button" class="text-blue-700 hover:underline" @click="resetForm(row)">Edit</button>
                    <button v-if="capabilities.delete" type="button" class="text-red-700 hover:underline" @click="selectedIds = [row.id]; confirmAction = 'delete'">Delete</button>
                </div>
            </template>
        </CoreDataTable>

        <section v-if="detail" class="grid gap-3 rounded-lg border border-slate-200 bg-white p-5" aria-label="Record details">
            <h2 class="text-lg font-semibold">{{ descriptor.title }} details</h2>
            <dl class="grid gap-3 sm:grid-cols-2"><div v-for="column in descriptor.columns" :key="column.key"><dt class="text-xs font-semibold uppercase text-slate-500">{{ column.label }}</dt><dd class="mt-1 break-words text-sm text-slate-900">{{ detail[column.key as keyof T] ?? '—' }}</dd></div></dl>
            <div><CoreButton type="button" @click="detail = null">Close details</CoreButton></div>
        </section>

        <CoreToast v-if="notice" :message="notice" :variant="noticeVariant" @dismiss="notice = ''" />
        <CoreConfirmDialog
            :open="confirmAction !== null"
            title="Confirm delete"
            :message="confirmAction === 'bulk' ? `Delete ${selectedIds.length} selected records?` : (descriptor.messages?.deleteConfirmation ?? 'Delete this record?')"
            confirm-label="Delete"
            :destructive="true"
            @cancel="confirmAction = null"
            @confirm="runConfirmed"
        />
    </section>
</template>
