<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import AdminIcon from './AdminIcon.vue';
import DashboardReadinessDonut from './DashboardReadinessDonut.vue';
import DashboardStatCard from './DashboardStatCard.vue';
import DashboardUsersTable from './DashboardUsersTable.vue';

interface DashboardData {
    generated_at: string;
    current_user: { name: string };
    access: Record<string, boolean>;
    metrics: { users: number | null; active_users: number | null; inactive_users: number | null; roles: number | null; permissions: number | null; modules: number | null };
    user_growth: Array<{ label: string; count: number }>;
    recent_activity: Array<{ id: string; event_name: string; category: string; outcome: string; actor: { type: string | null; id: string } | null; occurred_at: string | null }>;
    health: null | { overall_status: string; checks: Array<{ name: string; status: string }> };
    module_readiness: Record<string, number>;
}
const data = ref<DashboardData | null>(null);
const loading = ref(true);
const error = ref('');
const dashboardApiBase = document.querySelector<HTMLElement>('#master-core-auth')?.dataset.dashboardApiBase ?? '/api/v1/core/dashboard';
const greeting = computed(() => {
    const hour = new Date().getHours();
    return hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
});
const maxGrowth = computed(() => Math.max(1, ...(data.value?.user_growth.map((point) => point.count) ?? [])));
const growthTotal = computed(() => data.value?.user_growth.reduce((total, point) => total + point.count, 0) ?? 0);
const stats = computed(() => {
    const metrics = data.value?.metrics;
    return [
        { label: 'Total users', value: metrics?.users, note: 'Registered accounts', tone: 'blue' as const, icon: 'users' },
        { label: 'Active users', value: metrics?.active_users, note: metrics?.inactive_users == null ? 'Current active accounts' : `${metrics.inactive_users} inactive`, tone: 'green' as const, icon: 'users' },
        { label: 'Roles', value: metrics?.roles, note: 'Configured access roles', tone: 'amber' as const, icon: 'shield' },
        { label: 'Permissions', value: metrics?.permissions, note: 'Available permissions', tone: 'violet' as const, icon: 'key' },
        { label: 'Modules', value: metrics?.modules, note: 'Installed Core modules', tone: 'pink' as const, icon: 'layers' },
    ].filter((stat) => stat.value != null);
});

async function load(): Promise<void> {
    loading.value = true;
    error.value = '';
    try { data.value = (await axios.get<{ data: DashboardData }>(dashboardApiBase)).data.data; }
    catch { error.value = 'Dashboard information could not be loaded. Please refresh the page.'; }
    finally { loading.value = false; }
}
function formatDate(value: string | null): string {
    if (!value) return 'Time unavailable';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Time unavailable' : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}
function eventLabel(value: string): string {
    return value.replace(/[._-]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}
function statusLabel(value: string): string { return value.replaceAll('_', ' '); }
onMounted(() => { void load(); });
</script>

<template>
    <div class="mc-dashboard">
        <header class="mc-dashboard__welcome">
            <div><p class="mc-dashboard__eyebrow">WORKSPACE OVERVIEW</p><h1>{{ greeting }}, {{ data?.current_user.name ?? 'there' }}</h1><p>Your workspace at a glance.</p></div>
            <div class="mc-dashboard__date"><AdminIcon name="calendar" /><span>{{ new Intl.DateTimeFormat(undefined, { dateStyle: 'full' }).format(new Date()) }}</span></div>
        </header>

        <div v-if="loading || !data" class="mc-dashboard__loading-state" :aria-busy="loading" aria-label="Dashboard content">
            <div v-if="loading" class="mc-dashboard__loading-label" role="status">Loading dashboard...</div>
            <div v-else class="mc-dashboard__error" role="alert"><span>{{ error || 'Dashboard information is unavailable.' }}</span><button type="button" @click="load">Retry</button></div>
            <section class="mc-dashboard__stats mc-dashboard__stats--skeleton" aria-hidden="true">
                <div v-for="card in 5" :key="card" class="mc-stat"><span class="mc-skeleton mc-skeleton--label" /><span class="mc-skeleton mc-skeleton--value" /><span class="mc-skeleton mc-skeleton--note" /></div>
            </section>
            <section class="mc-dashboard__widgets mc-dashboard__widgets--skeleton" aria-hidden="true">
                <article v-for="widget in ['growth', 'health', 'modules']" :key="widget" class="mc-panel"><span class="mc-skeleton mc-skeleton--heading" /><span class="mc-skeleton mc-skeleton--subheading" /><span class="mc-skeleton mc-skeleton--chart" /></article>
            </section>
            <section class="mc-dashboard__lower mc-dashboard__lower--skeleton" aria-hidden="true">
                <article class="mc-panel"><span class="mc-skeleton mc-skeleton--heading" /><span class="mc-skeleton mc-skeleton--activity" /><span class="mc-skeleton mc-skeleton--activity" /><span class="mc-skeleton mc-skeleton--activity" /></article>
                <article class="mc-panel"><span class="mc-skeleton mc-skeleton--heading" /><span class="mc-skeleton mc-skeleton--subheading" /><span class="mc-skeleton mc-skeleton--table" /></article>
            </section>
        </div>
        <template v-else-if="data">
            <section v-if="stats.length" class="mc-dashboard__stats" aria-label="Workspace metrics">
                <DashboardStatCard v-for="stat in stats" :key="stat.label" v-bind="stat" :value="stat.value ?? 0" />
            </section>

            <section class="mc-dashboard__widgets" aria-label="Dashboard analytics">
                <article v-if="data.access.users" class="mc-panel mc-panel--growth">
                    <header class="mc-panel__heading"><div><h2>User growth</h2><p>New accounts created by month</p></div><span class="mc-panel__period">Last 6 months</span></header>
                    <div v-if="data.user_growth.length" class="mc-growth-summary"><strong>{{ growthTotal.toLocaleString() }}</strong><span>new users in this period</span></div>
                    <div v-if="data.user_growth.length" class="mc-chart" role="img" :aria-label="`New users during the last six months. ${data.user_growth.map((point) => `${point.label}: ${point.count}`).join(', ')}`">
                        <div v-for="point in data.user_growth" :key="point.label" class="mc-chart__column"><strong>{{ point.count }}</strong><div class="mc-chart__track"><span :style="{ height: `${point.count ? Math.max(7, (point.count / maxGrowth) * 100) : 0}%` }" /></div><small>{{ point.label }}</small></div>
                    </div><div v-else class="mc-panel__empty">No user registrations in this period.</div>
                </article>

                <article v-if="data.health" class="mc-panel mc-panel--health">
                    <header class="mc-panel__heading"><div><h2>System health</h2><p>Latest application checks</p></div><RouterLink to="/app/system-health" class="mc-panel__link">View details <span aria-hidden="true">→</span></RouterLink></header>
                    <div class="mc-health__overall"><span class="mc-health__dot" :class="`status-${data.health.overall_status}`" /><strong>{{ statusLabel(data.health.overall_status) }}</strong></div>
                    <ul v-if="data.health.checks.length" class="mc-health__checks"><li v-for="check in data.health.checks" :key="check.name"><span>{{ check.name }}</span><span class="mc-health__status" :class="`status-text-${check.status}`"><i :class="`status-${check.status}`" />{{ statusLabel(check.status) }}</span></li></ul>
                    <p v-else class="mc-panel__empty">No health checks are available.</p>
                </article>

                <article v-if="data.access.modules" class="mc-panel mc-panel--modules">
                    <header class="mc-panel__heading"><div><h2>Module readiness</h2><p>Current module inventory</p></div><RouterLink to="/app/modules" class="mc-panel__link">Module manager <span aria-hidden="true">→</span></RouterLink></header>
                    <DashboardReadinessDonut :readiness="data.module_readiness" :total="data.metrics.modules" />
                </article>
            </section>

            <section class="mc-dashboard__lower">
                <article v-if="data.access.audit" class="mc-panel mc-panel--activity">
                    <header class="mc-panel__heading"><div><h2>Recent activity</h2><p>Latest recorded audit events</p></div><RouterLink to="/app/audit-logs" class="mc-panel__link">View all <span aria-hidden="true">→</span></RouterLink></header>
                    <ul v-if="data.recent_activity.length" class="mc-activity"><li v-for="event in data.recent_activity" :key="event.id"><span class="mc-activity__marker"><AdminIcon name="activity" /></span><div class="mc-activity__body"><strong>{{ eventLabel(event.event_name) }}</strong><small>{{ event.actor ? `${event.actor.type ?? 'User'} ${event.actor.id}` : 'System' }} · {{ event.category }} · {{ event.outcome }}</small></div><time :datetime="event.occurred_at ?? undefined">{{ formatDate(event.occurred_at) }}</time></li></ul>
                    <div v-else class="mc-panel__empty">No audit events have been recorded.</div>
                </article>
                <article v-else class="mc-panel mc-panel--activity"><header class="mc-panel__heading"><div><h2>Workspace shortcuts</h2><p>Pages available to your account</p></div></header><div class="mc-dashboard__shortcuts">
                    <RouterLink v-if="data.access.users" to="/app/users"><AdminIcon name="users" />User management <span>→</span></RouterLink>
                    <RouterLink v-if="data.access.roles" to="/app/roles"><AdminIcon name="shield" />Roles <span>→</span></RouterLink>
                    <RouterLink v-if="data.access.permissions" to="/app/permissions"><AdminIcon name="key" />Permissions <span>→</span></RouterLink>
                </div></article>
                <DashboardUsersTable v-if="data.access.users" />
            </section>
            <p v-if="!stats.length && !data.access.audit && !data.health && !data.access.modules && !data.access.users" class="mc-dashboard__limited">Your account does not currently have access to dashboard metrics or modules.</p>
            <p class="mc-dashboard__updated">Dashboard data generated {{ formatDate(data.generated_at) }}</p>
        </template>
    </div>
</template>
