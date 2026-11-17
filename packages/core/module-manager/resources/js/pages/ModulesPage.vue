<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { CoreButton, CoreDataTable, CoreTextInput } from '@itassistance-bd/ui';
import type { CoreDataTableColumn } from '@itassistance-bd/ui';
import { getModule, listModules, moduleApiError } from '../api';
import type { ModuleFilters, ModulePageMeta, ModuleReadiness, ModuleRecord } from '../types';

interface ModuleRow extends ModuleRecord { installation_status: string }

const rows = ref<ModuleRow[]>([]);
const detail = ref<ModuleRecord | null>(null);
const loading = ref(false);
const error = ref('');
const forbidden = ref(false);
const sessionExpired = ref(false);
const searchText = ref('');
const meta = ref<ModulePageMeta>({ current_page: 1, last_page: 1, per_page: 10, total: 0 });
const filters = reactive<ModuleFilters>({ page: 1, per_page: 10, sort_by: 'name', sort_direction: 'asc' });
const columns: CoreDataTableColumn[] = [
    { key: 'name', label: 'Module', sortable: true },
    { key: 'key', label: 'Identifier', sortable: true },
    { key: 'composer_package', label: 'Composer package', sortable: true },
    { key: 'installation_status', label: 'Installation' },
    { key: 'readiness_status', label: 'Readiness', sortable: true },
];

async function load(): Promise<void> {
    loading.value = true;
    error.value = '';
    forbidden.value = false;
    sessionExpired.value = false;

    try {
        const result = await listModules(filters);
        rows.value = result.modules.map((module) => ({
            ...module,
            installation_status: module.installation.installed
                ? module.installation.version ?? 'Installed; version unavailable'
                : 'Not installed',
        }));
        meta.value = result.meta;
    } catch (cause) {
        const failure = moduleApiError(cause);
        forbidden.value = failure.status === 403;
        sessionExpired.value = failure.status === 401;
        if (forbidden.value || sessionExpired.value) {
            rows.value = [];
            detail.value = null;
        }
        error.value = forbidden.value ? 'You do not have permission to view Core modules.' : failure.message;
    } finally {
        loading.value = false;
    }
}

async function showDetails(key: string): Promise<void> {
    detail.value = null;
    error.value = '';
    forbidden.value = false;
    sessionExpired.value = false;
    try {
        detail.value = await getModule(key);
    } catch (cause) {
        const failure = moduleApiError(cause);
        forbidden.value = failure.status === 403;
        sessionExpired.value = failure.status === 401;
        if (forbidden.value || sessionExpired.value) rows.value = [];
        error.value = forbidden.value ? 'You do not have permission to view Core module details.' : failure.message;
    }
}

function submitSearch(): void {
    filters.search = searchText.value.trim() || undefined;
    filters.page = 1;
    void load();
}

function updatePage(page: number): void { filters.page = page; void load(); }
function updatePerPage(perPage: number): void { filters.per_page = perPage; filters.page = 1; void load(); }
function updateSort(key: string, direction: 'asc' | 'desc'): void {
    const allowed = ['name', 'key', 'composer_package', 'readiness_status'];
    if (!allowed.includes(key)) return;
    filters.sort_by = key as NonNullable<ModuleFilters['sort_by']>;
    filters.sort_direction = direction;
    void load();
}

function readinessLabel(status: ModuleReadiness): string {
    const labels: Record<ModuleReadiness, string> = {
        not_installed: 'Not installed',
        dependency_missing: 'Dependency missing',
        provider_not_loaded: 'Provider not loaded',
        runtime_not_verified: 'Runtime not verified',
    };
    return labels[status];
}

onMounted(() => { void load(); });
</script>

<template>
    <main class="module-page">
        <header class="module-page__heading">
            <div>
                <h1>Core Modules</h1>
                <p>Read-only inventory of approved Core packages and dependency signals.</p>
            </div>
        </header>

        <form class="module-search" role="search" @submit.prevent="submitSearch">
            <CoreTextInput id="module-search" v-model="searchText" type="text" aria-label="Search Core modules" placeholder="Search modules or packages" />
            <label class="module-search__filter" for="module-readiness">Readiness
                <select id="module-readiness" v-model="filters.readiness" class="mc-control">
                    <option value="">All readiness states</option>
                    <option value="not_installed">Not installed</option>
                    <option value="dependency_missing">Dependency missing</option>
                    <option value="provider_not_loaded">Provider not loaded</option>
                    <option value="runtime_not_verified">Runtime not verified</option>
                </select>
            </label>
            <CoreButton type="submit" variant="secondary" :loading="loading">Search</CoreButton>
        </form>

        <p v-if="forbidden" class="module-state module-state--error" role="alert">{{ error }}</p>
        <p v-else-if="sessionExpired" class="module-state module-state--error" role="alert">{{ error }} <a href="/login">Sign in</a></p>
        <p v-else-if="error" class="module-state module-state--error" role="alert">{{ error }}</p>
        <p v-else-if="loading" class="module-state" role="status">Loading Core modules…</p>

        <CoreDataTable
            v-if="!loading && !error"
            :columns="columns"
            :rows="rows"
            row-key="key"
            :page="meta.current_page"
            :last-page="meta.last_page"
            :total="meta.total"
            :per-page="meta.per_page"
            :loading="loading"
            :selectable="false"
            :sort-by="filters.sort_by"
            :sort-direction="filters.sort_direction"
            preference-key="master-core.modules"
            :export-enabled="true"
            export-filename="master-core-modules.csv"
            :active-filter-count="Number(Boolean(searchText.trim())) + Number(Boolean(filters.readiness))"
            empty-message="No Core modules match this search."
            @update:page="updatePage"
            @update:per-page="updatePerPage"
            @update:sort="updateSort"
            @reset-filters="searchText = ''; filters.search = undefined; filters.readiness = undefined; filters.page = 1; load()"
        >
            <template #cell-readiness_status="{ row }">{{ readinessLabel(row.readiness_status) }}</template>
            <template #actions="{ row }"><CoreButton variant="text" @click="showDetails(row.key)">View</CoreButton></template>
        </CoreDataTable>

        <section v-if="detail" class="module-detail" aria-labelledby="module-detail-title">
            <div class="module-detail__heading">
                <div><h2 id="module-detail-title">{{ detail.name }}</h2><p>{{ detail.description }}</p></div>
                <CoreButton variant="text" @click="detail = null">Close</CoreButton>
            </div>
            <dl>
                <dt>Identifier</dt><dd>{{ detail.key }}</dd>
                <dt>Composer package</dt><dd>{{ detail.composer_package }}</dd>
                <dt>Installation</dt><dd>{{ detail.installation.installed ? `Installed (${detail.installation.version ?? 'version unavailable'})` : 'Not installed' }}</dd>
                <dt>Provider</dt><dd>{{ detail.provider_loaded ? 'Loaded by Laravel' : 'Not loaded by Laravel' }}</dd>
                <dt>Readiness</dt><dd>{{ readinessLabel(detail.readiness_status) }}. Package presence does not verify migrations, host setup, or feature operation.</dd>
                <dt>Documentation</dt><dd><code>{{ detail.documentation }}</code></dd>
            </dl>
            <h3>Declared dependencies</h3>
            <ul>
                <li v-for="dependency in detail.dependencies" :key="dependency.package_name">
                    {{ dependency.package_name }} — {{ dependency.installed ? 'available' : 'missing' }}
                </li>
                <li v-if="detail.dependencies.length === 0">No declared Composer dependencies.</li>
            </ul>
        </section>
    </main>
</template>

<style scoped>
.module-page { display: grid; gap: 1.25rem; max-width: 80rem; margin: 0 auto; padding: 1.5rem; }
.module-page__heading { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.module-page h1 { margin: 0; color: #0f172a; font-size: 1.5rem; font-weight: 700; }
.module-page__heading p, .module-detail__heading p { margin: .35rem 0 0; color: #475569; }
.module-search { display: flex; flex-wrap: wrap; align-items: end; gap: .75rem; }
.module-search__filter { display: grid; gap: .3rem; color: #334155; font-size: .875rem; font-weight: 600; }
.module-state { padding: 1rem; border-radius: .5rem; background: #f8fafc; color: #475569; }
.module-state--error { background: #fef2f2; color: #991b1b; }
.module-state a { margin-left: .25rem; color: #1d4ed8; text-decoration: underline; }
.module-detail { display: grid; gap: .75rem; padding: 1.25rem; border: 1px solid #cbd5e1; border-radius: .5rem; background: white; }
.module-detail__heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.module-detail h2, .module-detail h3 { margin: 0; color: #0f172a; font-size: 1.125rem; font-weight: 650; }
.module-detail dl { display: grid; grid-template-columns: minmax(9rem, 1fr) 3fr; gap: .5rem 1rem; margin: 0; }
.module-detail dt { color: #475569; font-weight: 600; }
.module-detail dd { min-width: 0; margin: 0; overflow-wrap: anywhere; }
.module-detail ul { margin: 0; padding-left: 1.25rem; color: #334155; }
@media (max-width: 640px) { .module-page { padding: 1rem; } .module-detail dl { grid-template-columns: 1fr; } }
</style>
