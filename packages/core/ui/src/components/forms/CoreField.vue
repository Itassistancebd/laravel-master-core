<script setup lang="ts">
import { computed, useId } from 'vue';

const props = withDefaults(defineProps<{
    id?: string;
    label: string;
    help?: string;
    error?: string;
    required?: boolean;
}>(), {
    help: '',
    error: '',
    required: false,
});

const generatedId = `core-field-${useId()}`;
const controlId = computed(() => props.id || generatedId);
const helpId = computed(() => `${controlId.value}-help`);
const errorId = computed(() => `${controlId.value}-error`);
const describedBy = computed(() => [props.help ? helpId.value : '', props.error ? errorId.value : ''].filter(Boolean).join(' ') || undefined);
</script>

<template>
    <div class="mc-field">
        <label class="mc-field__label" :for="controlId">
            {{ label }}
            <span v-if="required" class="mc-field__required" aria-hidden="true">*</span>
        </label>
        <slot :id="controlId" :described-by="describedBy" :error-id="error ? errorId : undefined" />
        <p v-if="help" :id="helpId" class="mc-field__help">{{ help }}</p>
        <p v-if="error" :id="errorId" class="mc-field__error" role="alert">{{ error }}</p>
    </div>
</template>
