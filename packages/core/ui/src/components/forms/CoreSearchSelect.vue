<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import type { CoreSelectOption } from '../../types';

defineOptions({ inheritAttrs: false });

const props = withDefaults(defineProps<{
    id: string;
    modelValue: string | number | null;
    options: CoreSelectOption[];
    placeholder?: string;
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
}>(), {
    placeholder: 'Search options',
    disabled: false,
    invalid: false,
});

const emit = defineEmits<{ 'update:modelValue': [value: string | number] }>();
const listId = `mc-select-list-${useId()}`;
const root = ref<HTMLElement | null>(null);
const query = ref('');
const isOpen = ref(false);
const activeIndex = ref(0);
const selectedOption = computed(() => props.options.find((option) => String(option.value) === String(props.modelValue)));
const visibleOptions = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase();

    return needle ? props.options.filter((option) => option.label.toLocaleLowerCase().includes(needle)) : props.options;
});

watch(visibleOptions, () => { activeIndex.value = 0; });

function open(): void {
    if (!props.disabled) {
        query.value = '';
        isOpen.value = true;
    }
}

function close(): void {
    isOpen.value = false;
    query.value = '';
}

function onFocusout(event: FocusEvent): void {
    if (!root.value?.contains(event.relatedTarget as Node | null)) {
        close();
    }
}

function choose(option: CoreSelectOption): void {
    emit('update:modelValue', option.value);
    close();
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        close();
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        open();
        activeIndex.value = Math.min(activeIndex.value + 1, visibleOptions.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        open();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    } else if (event.key === 'Enter' && isOpen.value && visibleOptions.value[activeIndex.value]) {
        event.preventDefault();
        choose(visibleOptions.value[activeIndex.value]);
    }
}
</script>

<template>
    <div ref="root" class="mc-search-select" @focusout="onFocusout">
        <input
            :id="id"
            class="mc-control"
            :class="{ 'mc-control--invalid': invalid }"
            type="text"
            role="combobox"
            autocomplete="off"
            :value="isOpen ? query : (selectedOption?.label ?? '')"
            :placeholder="placeholder"
            :disabled="disabled"
            :aria-expanded="isOpen"
            :aria-controls="listId"
            :aria-activedescendant="isOpen && visibleOptions[activeIndex] ? `${listId}-option-${activeIndex}` : undefined"
            :aria-invalid="invalid || undefined"
            :aria-describedby="describedBy"
            @focus="open"
            @input="query = ($event.target as HTMLInputElement).value; isOpen = true"
            @keydown="onKeydown"
        >
        <ul v-if="isOpen" :id="listId" class="mc-select-list" role="listbox">
            <li v-for="(option, index) in visibleOptions" :id="`${listId}-option-${index}`" :key="String(option.value)" role="none">
                <button
                    type="button"
                    class="mc-select-option"
                    :class="{ 'mc-select-option--active': index === activeIndex, 'mc-select-option--selected': String(option.value) === String(modelValue) }"
                    role="option"
                    :aria-selected="String(option.value) === String(modelValue)"
                    @mousedown.prevent
                    @click="choose(option)"
                >
                    {{ option.label }}
                </button>
            </li>
            <li v-if="visibleOptions.length === 0" class="mc-select-empty" role="status">No matching options</li>
        </ul>
    </div>
</template>
