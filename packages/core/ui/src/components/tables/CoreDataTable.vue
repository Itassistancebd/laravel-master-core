<script setup lang="ts" generic="TRow extends Record<string, unknown>">
import { computed, onMounted, ref, useSlots, watch } from 'vue';
import { selectedRowsOnPage, serializeCsv } from './csv';

export interface CoreDataTableColumn {
    key: string;
    label: string;
    sortable?: boolean;
    required?: boolean;
    exportable?: boolean;
}

const props = withDefaults(defineProps<{
    columns: CoreDataTableColumn[];
    rows: TRow[];
    rowKey?: string;
    page: number;
    lastPage: number;
    total: number;
    perPage: number;
    selectedIds?: Array<string | number>;
    visibleColumnKeys?: string[];
    loading?: boolean;
    error?: string;
    sortBy?: string;
    sortDirection?: 'asc' | 'desc';
    selectable?: boolean;
    emptyMessage?: string;
    preferenceKey?: string;
    exportEnabled?: boolean;
    exportFilename?: string;
    activeFilterCount?: number;
}>(), {
    rowKey: 'id',
    selectedIds: () => [],
    loading: false,
    error: '',
    sortBy: '',
    sortDirection: 'asc',
    selectable: true,
    emptyMessage: 'No records match your search.',
    exportEnabled: false,
    exportFilename: 'records.csv',
    activeFilterCount: 0,
});

const emit = defineEmits<{
    'update:page': [page: number];
    'update:perPage': [perPage: number];
    'update:sort': [key: string, direction: 'asc' | 'desc'];
    'update:selectedIds': [ids: Array<string | number>];
    'update:visibleColumnKeys': [keys: string[]];
    'reset-filters': [];
}>();

const slots = useSlots();
const internalVisibleColumnKeys = ref<string[]>([]);
const visibleColumns = computed(() => {
    const visible = new Set(props.visibleColumnKeys ?? (internalVisibleColumnKeys.value.length ? internalVisibleColumnKeys.value : props.columns.map((column) => column.key)));
    for (const column of props.columns) if (column.required) visible.add(column.key);
    return props.columns.filter((column) => visible.has(column.key));
});
const pageLinks = computed(() => {
    if (props.lastPage < 1) return [];
    const first = Math.max(1, Math.min(props.page - 2, props.lastPage - 4));
    const last = Math.min(props.lastPage, first + 4);
    return Array.from({ length: last - first + 1 }, (_, index) => first + index);
});
const allVisibleSelected = computed(() => props.rows.length > 0 && props.rows.every((row) => props.selectedIds.includes(row[props.rowKey] as string | number)));
const from = computed(() => props.total === 0 ? 0 : ((props.page - 1) * props.perPage) + 1);
const to = computed(() => Math.min(props.page * props.perPage, props.total));
const selectedCurrentRows = computed(() => selectedRowsOnPage(props.rows, props.selectedIds, props.rowKey));

onMounted(() => {
    internalVisibleColumnKeys.value = props.columns.map((column) => column.key);
    if (!props.preferenceKey) return;
    try {
        const saved = window.localStorage.getItem(`mc-table-columns:${props.preferenceKey}`);
        if (!saved) return;
        const keys = JSON.parse(saved);
        if (!Array.isArray(keys)) return;
        const allowed = new Set(props.columns.map((column) => column.key));
        const normalized = [...new Set(keys.filter((key): key is string => typeof key === 'string' && allowed.has(key)))];
        for (const column of props.columns) if (column.required && !normalized.includes(column.key)) normalized.push(column.key);
        internalVisibleColumnKeys.value = normalized;
        emit('update:visibleColumnKeys', normalized);
    } catch { /* Preferences are optional; storage may be unavailable or malformed. */ }
});

watch(visibleColumns, (columns) => {
    if (!props.preferenceKey) return;
    try {
        const allowed = new Set(props.columns.map((column) => column.key));
        const normalized = [...new Set(columns.map((column) => column.key).filter((key) => allowed.has(key)))];
        for (const column of props.columns) if (column.required && !normalized.includes(column.key)) normalized.push(column.key);
        window.localStorage.setItem(`mc-table-columns:${props.preferenceKey}`, JSON.stringify(normalized));
    } catch { /* Preferences are optional; storage may be unavailable. */ }
}, { deep: true });

function rowId(row: TRow): string | number {
    return row[props.rowKey] as string | number;
}

function toggleOne(row: TRow): void {
    const id = rowId(row);
    const selected = new Set(props.selectedIds);
    selected.has(id) ? selected.delete(id) : selected.add(id);
    emit('update:selectedIds', [...selected]);
}

function toggleVisibleRows(): void {
    const selected = new Set(props.selectedIds);
    for (const row of props.rows) {
        const id = rowId(row);
        allVisibleSelected.value ? selected.delete(id) : selected.add(id);
    }
    emit('update:selectedIds', [...selected]);
}

function toggleColumn(key: string): void {
    if (props.columns.find((column) => column.key === key)?.required) return;
    const current = new Set(visibleColumns.value.map((column) => column.key));
    current.has(key) ? current.delete(key) : current.add(key);
    const keys = [...current];
    internalVisibleColumnKeys.value = keys;
    emit('update:visibleColumnKeys', keys);
}

function resetColumns(): void {
    const keys = props.columns.map((column) => column.key);
    internalVisibleColumnKeys.value = keys;
    emit('update:visibleColumnKeys', keys);
}

function exportRows(rows: TRow[], suffix: string): void {
    if (!props.exportEnabled || rows.length === 0) return;
    const columnsToExport = visibleColumns.value.filter((column) => column.exportable !== false);
    if (!columnsToExport.length) return;
    const csv = serializeCsv(columnsToExport.map(({ key, label }) => ({ key, label })), rows);
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = props.exportFilename.replace(/\.csv$/i, '') + suffix + '.csv';
    link.click();
    URL.revokeObjectURL(url);
}

function sort(column: CoreDataTableColumn): void {
    if (!column.sortable) return;
    const direction = props.sortBy === column.key && props.sortDirection === 'asc' ? 'desc' : 'asc';
    emit('update:sort', column.key, direction);
}
</script>

<template>
    <section class="mc-table" aria-label="Data table">
        <div class="mc-table__toolbar">
            <p class="mc-table__count" aria-live="polite">Showing {{ from }}–{{ to }} of {{ total }}</p>
            <div class="mc-table__toolbar-actions">
                <button v-if="activeFilterCount > 0" type="button" class="mc-table__tool-button" @click="emit('reset-filters')">Clear filters ({{ activeFilterCount }})</button>
                <button v-if="exportEnabled" type="button" class="mc-table__tool-button" :disabled="rows.length === 0" @click="exportRows(rows, '-current-page')">Export current page</button>
                <button v-if="exportEnabled && selectable" type="button" class="mc-table__tool-button" :disabled="selectedCurrentRows.length === 0" @click="exportRows(selectedCurrentRows, '-selected-current-page')">Export selected on page ({{ selectedCurrentRows.length }})</button>
            </div>
            <details class="mc-table__columns">
                <summary>Columns</summary>
                <fieldset>
                    <legend>Choose visible columns</legend>
                    <label v-for="column in columns" :key="column.key">
                        <input
                            type="checkbox"
                            :checked="visibleColumns.some((visible) => visible.key === column.key)"
                            :disabled="column.required"
                            @change="toggleColumn(column.key)"
                        >
                        {{ column.label }}
                    </label>
                    <button type="button" class="mc-table__tool-button" @click="resetColumns">Reset columns</button>
                </fieldset>
            </details>
        </div>

        <slot v-if="error" name="error">
            <p class="mc-table__state mc-table__state--error" role="alert">{{ error }}</p>
        </slot>
        <p v-else-if="loading" class="mc-table__state" role="status" aria-live="polite">Loading records…</p>
        <p v-else-if="rows.length === 0" class="mc-table__state" role="status">{{ emptyMessage }}</p>

        <div v-else class="mc-table__scroll">
            <table>
                <thead>
                    <tr>
                        <th v-if="selectable" scope="col" class="mc-table__select">
                            <input
                                type="checkbox"
                                aria-label="Select all rows on this page"
                                :checked="allVisibleSelected"
                                @change="toggleVisibleRows"
                            >
                        </th>
                        <th scope="col" class="mc-table__serial">SL</th>
                        <th v-for="column in visibleColumns" :key="column.key" scope="col" :aria-sort="sortBy === column.key ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none'">
                            <button v-if="column.sortable" type="button" class="mc-table__sort" @click="sort(column)">
                                {{ column.label }}<span class="mc-table__sort-indicator" aria-hidden="true">{{ sortBy === column.key ? (sortDirection === 'asc' ? ' ↑' : ' ↓') : '' }}</span>
                            </button>
                            <span v-else>{{ column.label }}</span>
                        </th>
                        <th v-if="slots.actions" scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in rows" :key="rowId(row)">
                        <td v-if="selectable" class="mc-table__select">
                            <input
                                type="checkbox"
                                :aria-label="`Select row ${from + index}`"
                                :checked="selectedIds.includes(rowId(row))"
                                @change="toggleOne(row)"
                            >
                        </td>
                        <td class="mc-table__serial">{{ from + index }}</td>
                        <td v-for="column in visibleColumns" :key="column.key">
                            <slot :name="`cell-${column.key}`" :row="row">{{ row[column.key] }}</slot>
                        </td>
                        <td v-if="slots.actions"><slot name="actions" :row="row" /></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <footer class="mc-table__footer">
            <label class="mc-table__page-size">Rows per page
                <select :value="perPage" @change="emit('update:perPage', Number(($event.target as HTMLSelectElement).value))">
                    <option v-for="size in [10, 25, 50, 100]" :key="size" :value="size">{{ size }}</option>
                </select>
            </label>
            <nav class="mc-table__pagination" aria-label="Table pagination">
                <button type="button" aria-label="First page" :disabled="page <= 1 || loading" @click="emit('update:page', 1)">First</button>
                <button type="button" aria-label="Previous page" :disabled="page <= 1 || loading" @click="emit('update:page', page - 1)">Previous</button>
                <button
                    v-for="link in pageLinks"
                    :key="link"
                    type="button"
                    :aria-current="page === link ? 'page' : undefined"
                    :aria-label="`Page ${link}`"
                    :disabled="loading"
                    @click="emit('update:page', link)"
                >{{ link }}</button>
                <button type="button" aria-label="Next page" :disabled="page >= lastPage || loading" @click="emit('update:page', page + 1)">Next</button>
                <button type="button" aria-label="Last page" :disabled="page >= lastPage || loading" @click="emit('update:page', lastPage)">Last</button>
            </nav>
        </footer>
    </section>
</template>
