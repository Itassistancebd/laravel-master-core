<script setup lang="ts">
withDefaults(defineProps<{
    type?: 'button' | 'submit' | 'reset';
    variant?: 'primary' | 'secondary' | 'danger' | 'text';
    disabled?: boolean;
    loading?: boolean;
}>(), {
    type: 'button',
    variant: 'secondary',
    disabled: false,
    loading: false,
});
</script>

<template>
    <button
        :type="type"
        class="mc-button"
        :class="`mc-button--${variant}`"
        :disabled="disabled || loading"
        :aria-busy="loading || undefined"
    >
        <span v-if="loading" class="mc-button__spinner" aria-hidden="true" />
        <slot />
    </button>
</template>
