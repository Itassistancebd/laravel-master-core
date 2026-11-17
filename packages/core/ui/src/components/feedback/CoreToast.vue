<script setup lang="ts">
withDefaults(defineProps<{
    message: string;
    variant?: 'success' | 'error' | 'info';
}>(), {
    variant: 'info',
});

const emit = defineEmits<{ dismiss: [] }>();
</script>

<template>
    <div v-if="message" class="mc-toast" :class="`mc-toast--${variant}`" :role="variant === 'error' ? 'alert' : 'status'" :aria-live="variant === 'error' ? 'assertive' : 'polite'">
        <span>{{ message }}</span>
        <button type="button" aria-label="Dismiss notification" @click="emit('dismiss')">×</button>
    </div>
</template>
