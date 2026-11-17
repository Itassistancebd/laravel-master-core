<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import type { CoreSelectOption } from '../../types';

defineOptions({ inheritAttrs: false });

const props = withDefaults(defineProps<{
    id: string;
    modelValue: Array<string | number>;
    options: CoreSelectOption[];
    placeholder?: string;
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
}>(), {
    modelValue: () => [],
    placeholder: 'Search and select options',
    disabled: false,
    invalid: false,
});

const emit = defineEmits<{ 'update:modelValue': [value: Array<string | number>] }>();
const listId = `mc-multi-list-${useId()}`;
const root = ref<HTMLElement | null>(null);
const query = ref('');
const isOpen = ref(false);
const activeIndex = ref(0);
const selectedOptions = computed(() => props.options.filter((option) => props.modelValue.some((value) => String(value) === String(option.value))));
const visibleOptions = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase();

    return needle ? props.options.filter((option) => option.label.toLocaleLowerCase().includes(needle)) : props.options;
});

watch(visibleOptions, () => { activeIndex.value = 0; });

function toggle(option: CoreSelectOption): void {
    const selected = props.modelValue.some((value) => String(value) === String(option.value));
    const next = selected
        ? props.modelValue.filter((value) => String(value) !== String(option.value))
        : [...props.modelValue, option.value];

    emit('update:modelValue', next);
}

function remove(option: CoreSelectOption): void {
    emit('update:modelValue', props.modelValue.filter((value) => String(value) !== String(option.value)));
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

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        close();
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        isOpen.value = true;
        activeIndex.value = Math.min(activeIndex.value + 1, visibleOptions.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    } else if (event.key === 'Enter' && isOpen.value && visibleOptions.value[activeIndex.value]) {
        event.preventDefault();
        toggle(visibleOptions.value[activeIndex.value]);
    }
}
</script>

<template>
    <div ref="root" class="mc-multi-select" @focusout="onFocusout">
        <div class="mc-multi-select__control" :class="{ 'mc-control--invalid': invalid, 'mc-multi-select__control--disabled': disabled }">
            <span v-for="option in selectedOptions" :key="String(option.value)" class="mc-chip">
                {{ option.label }}
                <button type="button" :aria-label="`Remove ${option.label}`" :disabled="disabled" @click="remove(option)">×</button>
            </span>
            <input
                :id="id"
                class="mc-multi-select__input"
                type="text"
                role="combobox"
                autocomplete="off"
                :value="query"
                :placeholder="selectedOptions.length ? '' : placeholder"
                :disabled="disabled"
                :aria-expanded="isOpen"
                :aria-controls="listId"
                :aria-activedescendant="isOpen && visibleOptions[activeIndex] ? `${listId}-option-${activeIndex}` : undefined"
                :aria-invalid="invalid || undefined"
                :aria-describedby="describedBy"
                @focus="isOpen = true"
                @input="query = ($event.target as HTMLInputElement).value; isOpen = true"
                @keydown="onKeydown"
            >
        </div>
        <ul v-if="isOpen" :id="listId" class="mc-select-list" role="listbox" aria-multiselectable="true">
            <li v-for="(option, index) in visibleOptions" :id="`${listId}-option-${index}`" :key="String(option.value)" role="none">
                <button
                    type="button"
                    class="mc-select-option"
                    :class="{ 'mc-select-option--active': index === activeIndex }"
                    role="option"
                    :aria-selected="modelValue.some((value) => String(value) === String(option.value))"
                    @mousedown.prevent
                    @click="toggle(option)"
                >
                    <span class="mc-option-check" aria-hidden="true">{{ modelValue.some((value) => String(value) === String(option.value)) ? '✓' : '' }}</span>
                    {{ option.label }}
                </button>
            </li>
            <li v-if="visibleOptions.length === 0" class="mc-select-empty" role="status">No matching options</li>
        </ul>
    </div>
</template>
