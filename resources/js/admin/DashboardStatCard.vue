<script setup lang="ts">
import AdminIcon from './AdminIcon.vue';

defineProps<{
    label: string;
    value: number;
    note: string;
    icon: string;
    tone: 'blue' | 'green' | 'amber' | 'violet' | 'pink';
}>();
</script>

<template>
    <article class="mc-stat" :class="`mc-stat--${tone}`">
        <div class="mc-stat__top">
            <span>{{ label }}</span>
            <span class="mc-stat__icon" :class="`tone-${tone}`"><AdminIcon :name="icon" /></span>
        </div>
        <strong>{{ value.toLocaleString() }}</strong>
        <small>{{ note }}</small>
    </article>
</template>
