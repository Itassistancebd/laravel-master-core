<script setup lang="ts">
withDefaults(defineProps<{ id: string; modelValue: boolean; disabled?: boolean }>(), { disabled: false });
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>

<template>
    <input
        :id="id"
        class="mc-checkbox"
        type="checkbox"
        :checked="modelValue"
        :disabled="disabled"
        @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
    >
</template>
