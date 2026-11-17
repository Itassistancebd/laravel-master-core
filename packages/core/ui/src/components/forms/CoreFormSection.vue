<script setup lang="ts">
withDefaults(defineProps<{ number?: number | string; title: string; description?: string; columns?: 1 | 2 | 3 }>(), { number: undefined, description: '', columns: 3 });
</script>

<template>
    <section class="mc-form-section" :aria-labelledby="`mc-form-section-${number ?? title.replace(/[^a-z0-9]+/gi, '-')}`">
        <header class="mc-form-section__heading">
            <span v-if="number !== undefined" class="mc-form-section__number" aria-hidden="true">{{ number }}</span>
            <div>
                <h2 :id="`mc-form-section-${number ?? title.replace(/[^a-z0-9]+/gi, '-')}`">{{ title }}</h2>
                <p v-if="description">{{ description }}</p>
            </div>
        </header>
        <div class="mc-form-section__fields" :style="{ '--mc-form-columns': columns }"><slot /></div>
    </section>
</template>
