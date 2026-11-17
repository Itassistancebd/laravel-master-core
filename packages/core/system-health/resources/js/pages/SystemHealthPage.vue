<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { CoreButton } from '@itassistance-bd/ui';
import { getSystemHealth, systemHealthApiError } from '../api';
import type { HealthCheckStatus, SystemHealthSnapshot } from '../types';

const snapshot = ref<SystemHealthSnapshot | null>(null);
const loading = ref(false);
const error = ref('');
const forbidden = ref(false);
const sessionExpired = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    error.value = '';
    forbidden.value = false;
    sessionExpired.value = false;

    try {
        snapshot.value = await getSystemHealth();
    } catch (cause) {
        const failure = systemHealthApiError(cause);
        forbidden.value = failure.status === 403;
        sessionExpired.value = failure.status === 401;
        if (forbidden.value || sessionExpired.value) snapshot.value = null;
        error.value = failure.message;
    } finally {
        loading.value = false;
    }
}

function statusLabel(status: HealthCheckStatus): string {
    const labels: Record<HealthCheckStatus, string> = {
        healthy: 'Successful',
        unavailable: 'Unavailable',
        not_configured: 'Not configured',
    };
    return labels[status];
}

function outcomeLabel(status: HealthCheckStatus): string {
    return status === 'healthy' ? 'successful' : status === 'unavailable' ? 'failed' : 'not run';
}

onMounted(() => { void load(); });
</script>

<template>
    <main class="system-health-page">
        <header class="system-health-page__header">
            <div>
                <h1>System Health &amp; Readiness</h1>
                <p>Read-only results for the specific checks listed below. This is not a complete infrastructure health report.</p>
            </div>
            <CoreButton variant="secondary" :loading="loading" @click="load">Refresh checks</CoreButton>
        </header>

        <p v-if="loading && !snapshot" class="system-health-state" role="status">Running approved checks…</p>
        <p v-else-if="sessionExpired" class="system-health-state system-health-state--error" role="alert">
            {{ error }} <a href="/login">Sign in</a>
        </p>
        <p v-else-if="forbidden" class="system-health-state system-health-state--error" role="alert">{{ error }}</p>
        <p v-else-if="error && !snapshot" class="system-health-state system-health-state--error" role="alert">{{ error }}</p>
        <p v-if="error && snapshot" class="system-health-state system-health-state--error" role="alert">{{ error }}</p>

        <template v-if="snapshot">
            <section class="system-health-summary" aria-labelledby="system-health-summary-title">
                <h2 id="system-health-summary-title">Reported status: {{ statusLabel(snapshot.overall_status) }}</h2>
                <p>{{ snapshot.scope }}</p>
                <p>Checked at <time :datetime="snapshot.checked_at">{{ snapshot.checked_at }}</time></p>
            </section>

            <section aria-labelledby="system-health-checks-title">
                <h2 id="system-health-checks-title">Approved checks</h2>
                <p v-if="snapshot.checks.length === 0" class="system-health-state">No checks are configured.</p>
                <ul v-else class="system-health-checks">
                    <li v-for="check in snapshot.checks" :key="check.key" class="system-health-check">
                        <div class="system-health-check__heading">
                            <h3>{{ check.name }}</h3>
                            <span class="system-health-badge" :class="`system-health-badge--${check.status}`">
                                {{ statusLabel(check.status) }} · {{ outcomeLabel(check.status) }}
                            </span>
                        </div>
                        <p>{{ check.message }}</p>
                    </li>
                </ul>
            </section>
        </template>

        <p v-else-if="!loading && !error" class="system-health-state">No readiness results are available.</p>
    </main>
</template>

<style scoped>
.system-health-page { display: grid; gap: 1.25rem; max-width: 68rem; margin: 0 auto; padding: 1.5rem; }
.system-health-page__header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.system-health-page h1 { margin: 0; color: #0f172a; font-size: 1.5rem; font-weight: 700; }
.system-health-page h2 { margin: 0 0 .75rem; color: #0f172a; font-size: 1.125rem; font-weight: 650; }
.system-health-page h3 { margin: 0; color: #0f172a; font-size: 1rem; font-weight: 650; }
.system-health-page__header p, .system-health-summary p, .system-health-check p { margin: .35rem 0 0; color: #475569; }
.system-health-summary, .system-health-check { padding: 1.1rem; border: 1px solid #cbd5e1; border-radius: .5rem; background: #fff; }
.system-health-checks { display: grid; gap: .75rem; margin: 0; padding: 0; list-style: none; }
.system-health-check__heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; }
.system-health-badge { padding: .25rem .6rem; border-radius: 999px; font-size: .8rem; font-weight: 650; }
.system-health-badge--healthy { background: #dcfce7; color: #166534; }
.system-health-badge--unavailable { background: #fee2e2; color: #991b1b; }
.system-health-badge--not_configured { background: #fef3c7; color: #92400e; }
.system-health-state { padding: 1rem; border-radius: .5rem; background: #f8fafc; color: #475569; }
.system-health-state--error { background: #fef2f2; color: #991b1b; }
.system-health-state a { margin-left: .25rem; color: #1d4ed8; text-decoration: underline; }
@media (max-width: 640px) {
    .system-health-page { padding: 1rem; }
    .system-health-page__header { align-items: flex-start; flex-direction: column; }
}
</style>
