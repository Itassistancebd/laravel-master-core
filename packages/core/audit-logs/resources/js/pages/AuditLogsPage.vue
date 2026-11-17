<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { CoreDataTable } from '@itassistance-bd/ui';
import type { CoreDataTableColumn } from '@itassistance-bd/ui';
import { auditApiError, getAuditEvent, listAuditEvents } from '../api';
import type { AuditEventRecord, AuditFilters, AuditPageMeta } from '../types';

interface AuditRow extends AuditEventRecord { actor_label: string; subject_label: string; [key: string]: unknown }
const rows = ref<AuditRow[]>([]);
const detail = ref<AuditEventRecord | null>(null);
const loading = ref(false);
const error = ref('');
const forbidden = ref(false);
const meta = ref<AuditPageMeta>({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
const filters = reactive<AuditFilters>({ page: 1, per_page: 25, sort: 'occurred_at', direction: 'desc' });
const columns: CoreDataTableColumn[] = [
    { key: 'occurred_at', label: 'When', sortable: true },
    { key: 'event_name', label: 'Event' },
    { key: 'category', label: 'Category' },
    { key: 'outcome', label: 'Outcome' },
    { key: 'actor_label', label: 'Actor' },
    { key: 'subject_label', label: 'Subject' },
];

async function load(): Promise<void> {
    loading.value = true;
    error.value = '';
    forbidden.value = false;
    try {
        const result = await listAuditEvents(filters);
        rows.value = result.events.map((event): AuditRow => ({
            ...event,
            actor_label: event.actor?.id ?? 'System/guest',
            subject_label: event.subject?.id ?? '—',
        }));
        meta.value = result.meta;
    } catch (cause) {
        const failure = auditApiError(cause);
        forbidden.value = failure.status === 403;
        error.value = forbidden.value ? 'You do not have permission to view audit history.' : failure.message;
    } finally {
        loading.value = false;
    }
}

async function showDetail(id: string): Promise<void> {
    try {
        detail.value = await getAuditEvent(id);
    } catch (cause) {
        const failure = auditApiError(cause);
        forbidden.value = failure.status === 403;
        error.value = forbidden.value ? 'You do not have permission to view audit details.' : failure.message;
    }
}

function submitFilters(): void { filters.page = 1; void load(); }
function clearFilters(): void {
    Object.assign(filters, { search: '', from: '', to: '', event_name: '', category: '', outcome: '', actor_id: '', subject_id: '', correlation_id: '', page: 1 });
    void load();
}
function activeFilterCount(): number {
    return Object.entries(filters).filter(([key, value]) => !['page', 'per_page', 'sort', 'direction'].includes(key) && value !== undefined && value !== '').length;
}
function updatePage(page: number): void { filters.page = page; void load(); }
function updatePerPage(perPage: number): void { filters.per_page = perPage; filters.page = 1; void load(); }
function updateSort(key: string, direction: 'asc' | 'desc'): void {
    if (key !== 'occurred_at') return;
    filters.sort = 'occurred_at'; filters.direction = direction; void load();
}
function formatTime(value: string): string { return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)); }

onMounted(() => { void load(); });
</script>

<template>
    <main class="audit-page">
        <header class="audit-page__heading">
            <div>
                <h1>Audit Logs</h1>
                <p>Read-only security and administration history.</p>
            </div>
        </header>

        <form class="audit-filters" @submit.prevent="submitFilters">
            <label>Search events or IDs<input v-model="filters.search" class="mc-control" type="search" maxlength="120"></label>
            <label>From<input v-model="filters.from" class="mc-control" type="date"></label>
            <label>To<input v-model="filters.to" class="mc-control" type="date"></label>
            <label>Event name<input v-model="filters.event_name" class="mc-control" type="text" maxlength="120"></label>
            <label>Category<select v-model="filters.category" class="mc-control"><option value="">All categories</option><option value="authentication">Authentication</option><option value="user_management">User Management</option><option value="roles_permissions">Roles &amp; Permissions</option><option value="settings">Settings</option></select></label>
            <label>Outcome<select v-model="filters.outcome" class="mc-control"><option value="">All outcomes</option><option value="succeeded">Succeeded</option><option value="failed">Failed</option><option value="denied">Denied</option></select></label>
            <label>Actor ID<input v-model="filters.actor_id" class="mc-control" type="text" maxlength="191"></label>
            <label>Subject ID<input v-model="filters.subject_id" class="mc-control" type="text" maxlength="191"></label>
            <label>Correlation ID<input v-model="filters.correlation_id" class="mc-control" type="text" maxlength="64"></label>
            <button class="mc-button mc-button--primary" type="submit" :disabled="loading">Search</button>
        </form>

        <p v-if="forbidden" class="audit-state audit-state--error" role="alert">{{ error }}</p>
        <p v-else-if="error" class="audit-state audit-state--error" role="alert">{{ error }}</p>
        <p v-if="loading" class="audit-state" role="status">Loading audit history…</p>

        <CoreDataTable
            v-if="!loading && !forbidden && !error"
            :columns="columns"
            :rows="rows"
            row-key="id"
            :page="meta.current_page"
            :last-page="meta.last_page"
            :total="meta.total"
            :per-page="meta.per_page"
            :loading="loading"
            :selectable="false"
            sort-by="occurred_at"
            :sort-direction="filters.direction"
            preference-key="master-core.audit-logs"
            :active-filter-count="activeFilterCount()"
            empty-message="No audit events match these filters."
            @update:page="updatePage"
            @update:per-page="updatePerPage"
            @update:sort="updateSort"
            @reset-filters="clearFilters"
        >
            <template #cell-occurred_at="{ row }">{{ formatTime(row.occurred_at) }}</template>
            <template #actions="{ row }"><button type="button" class="mc-button mc-button--text" @click="showDetail(row.id)">View</button></template>
        </CoreDataTable>

        <section v-if="detail" class="audit-detail" aria-labelledby="audit-detail-title">
            <div class="audit-detail__heading"><h2 id="audit-detail-title">Event details</h2><button class="mc-button mc-button--text" type="button" @click="detail = null">Close</button></div>
            <dl>
                <dt>Event</dt><dd>{{ detail.event_name }}</dd>
                <dt>Occurred</dt><dd>{{ formatTime(detail.occurred_at) }}</dd>
                <dt>Actor</dt><dd>{{ detail.actor?.id ?? 'System/guest' }}</dd>
                <dt>Subject</dt><dd>{{ detail.subject?.id ?? '—' }}</dd>
                <dt>Correlation ID</dt><dd>{{ detail.correlation_id ?? '—' }}</dd>
                <dt>IP address</dt><dd>{{ detail.ip_address ?? 'Not recorded' }}</dd>
                <dt>User-Agent</dt><dd class="audit-detail__long">{{ detail.user_agent ?? 'Not recorded' }}</dd>
            </dl>
            <h3>Change summary</h3>
            <pre>{{ JSON.stringify({ before: detail.before_values, after: detail.after_values, metadata: detail.metadata }, null, 2) }}</pre>
        </section>
    </main>
</template>

<style scoped>
.audit-page { display: grid; gap: 1.25rem; max-width: 80rem; margin: 0 auto; padding: 1.5rem; }
.audit-page__heading, .audit-detail__heading { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.audit-page h1 { margin: 0; color: #0f172a; font-size: 1.5rem; font-weight: 700; }
.audit-page__heading p { margin: .35rem 0 0; color: #475569; }
.audit-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); align-items: end; gap: .75rem; }
.audit-filters label { display: grid; gap: .35rem; color: #334155; font-size: .875rem; font-weight: 600; }
.audit-state { padding: 1rem; border-radius: .5rem; background: #f8fafc; color: #475569; }
.audit-state--error { background: #fef2f2; color: #991b1b; }
.audit-detail { display: grid; gap: .75rem; padding: 1.25rem; border: 1px solid #cbd5e1; border-radius: .5rem; background: white; }
.audit-detail h2, .audit-detail h3 { margin: 0; color: #0f172a; }
.audit-detail dl { display: grid; grid-template-columns: minmax(8rem, 1fr) 3fr; gap: .5rem 1rem; margin: 0; }
.audit-detail dt { color: #475569; font-weight: 600; }
.audit-detail dd { min-width: 0; margin: 0; overflow-wrap: anywhere; }
.audit-detail__long { max-height: 8rem; overflow: auto; }
.audit-detail pre { max-height: 24rem; overflow: auto; padding: .75rem; border-radius: .375rem; background: #f8fafc; white-space: pre-wrap; overflow-wrap: anywhere; }
@media (max-width: 640px) { .audit-page { padding: 1rem; } .audit-detail dl { grid-template-columns: 1fr; } }
</style>
