<script setup lang="ts">
defineOptions({ inheritAttrs: false });

withDefaults(defineProps<{
    id: string;
    modelValue: string | number | null;
    type?: 'text' | 'email' | 'number' | 'url' | 'password';
    disabled?: boolean;
    invalid?: boolean;
}>(), {
    type: 'text',
    disabled: false,
    invalid: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();
</script>

<template>
    <input
        v-bind="$attrs"
        :id="id"
        class="mc-control"
        :class="{ 'mc-control--invalid': invalid }"
        :type="type"
        :value="modelValue ?? ''"
        :disabled="disabled"
        :aria-invalid="invalid || undefined"
        @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    >
</template>
