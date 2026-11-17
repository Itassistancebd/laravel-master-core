<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ readiness: Record<string, number>; total: number | null }>();
const radius = 43;
const circumference = 2 * Math.PI * radius;
const labels: Record<string, string> = {
    runtime_verified: 'Runtime verified',
    provider_not_loaded: 'Provider not loaded',
    dependency_missing: 'Dependency missing',
    runtime_not_verified: 'Runtime not verified',
    not_installed: 'Not installed',
};
const colors: Record<string, string> = {
    runtime_verified: '#16a34a',
    provider_not_loaded: '#f59e0b',
    dependency_missing: '#dc2626',
    runtime_not_verified: '#2563eb',
    not_installed: '#94a3b8',
};
const entries = computed(() => Object.entries(props.readiness)
    .filter(([, count]) => Number.isFinite(count) && count > 0));
const totalCount = computed(() => props.total ?? entries.value.reduce((sum, [, count]) => sum + count, 0));
const segments = computed(() => {
    const total = entries.value.reduce((sum, [, count]) => sum + count, 0);
    let offset = 0;

    return entries.value.map(([key, count]) => {
        const length = total > 0 ? (count / total) * circumference : 0;
        const segment = {
            key,
            count,
            label: labels[key] ?? key.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()),
            color: colors[key] ?? '#64748b',
            length,
            offset,
        };
        offset += length;
        return segment;
    });
});
const chartDescription = computed(() => segments.value.length
    ? segments.value.map((segment) => `${segment.label}: ${segment.count}`).join(', ')
    : 'No module readiness data is available.');
</script>

<template>
    <div class="mc-readiness-chart">
        <svg class="mc-readiness-chart__donut" viewBox="0 0 120 120" role="img" :aria-label="`Module readiness. ${chartDescription}`">
            <circle class="mc-readiness-chart__track" cx="60" cy="60" :r="radius" />
            <circle
                v-for="segment in segments"
                :key="segment.key"
                class="mc-readiness-chart__segment"
                cx="60"
                cy="60"
                :r="radius"
                :stroke="segment.color"
                :stroke-dasharray="`${segment.length} ${circumference - segment.length}`"
                :stroke-dashoffset="-segment.offset"
            />
            <text class="mc-readiness-chart__total" x="60" y="57">{{ totalCount.toLocaleString() }}</text>
            <text class="mc-readiness-chart__caption" x="60" y="73">Modules</text>
        </svg>
        <ul v-if="segments.length" class="mc-readiness-chart__legend" aria-label="Module readiness categories">
            <li v-for="segment in segments" :key="segment.key">
                <span class="mc-readiness-chart__swatch" :style="{ backgroundColor: segment.color }" />
                <span>{{ segment.label }}</span>
                <strong>{{ segment.count.toLocaleString() }}</strong>
            </li>
        </ul>
        <p v-else class="mc-panel__empty mc-readiness-chart__empty">No modules are listed.</p>
    </div>
</template>
