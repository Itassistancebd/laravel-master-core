<script setup lang="ts">
withDefaults(defineProps<{ id: string; modelValue: string; disabled?: boolean }>(), { disabled: false });
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <input
        :id="id"
        class="mc-control mc-control--color"
        type="color"
        :value="modelValue"
        :disabled="disabled"
        @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    >
</template>
