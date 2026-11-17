<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue';

const props = withDefaults(defineProps<{
    open: boolean;
    title: string;
    message: string;
    confirmLabel?: string;
    destructive?: boolean;
}>(), {
    confirmLabel: 'Confirm',
    destructive: false,
});

const emit = defineEmits<{ confirm: []; cancel: [] }>();
const titleId = `mc-dialog-title-${useId()}`;
const messageId = `mc-dialog-message-${useId()}`;
const confirmButton = ref<HTMLButtonElement | null>(null);
const confirmVariant = computed(() => props.destructive ? 'danger' : 'primary');

watch(() => props.open, async (isOpen) => {
    if (isOpen) {
        await nextTick();
        confirmButton.value?.focus();
    }
});

function keepFocusInside(event: KeyboardEvent): void {
    if (event.key !== 'Tab') return;

    const dialog = event.currentTarget as HTMLElement;
    const buttons = Array.from(dialog.querySelectorAll<HTMLButtonElement>('button:not(:disabled)'));
    const first = buttons[0];
    const last = buttons.at(-1);

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first?.focus();
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="mc-dialog-backdrop" @click.self="emit('cancel')">
            <section
                class="mc-dialog"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                :aria-describedby="messageId"
                @keydown="keepFocusInside"
                @keydown.esc.stop="emit('cancel')"
            >
                <h2 :id="titleId" class="mc-dialog__title">{{ title }}</h2>
                <p :id="messageId" class="mc-dialog__message">{{ message }}</p>
                <div class="mc-dialog__actions">
                    <button type="button" class="mc-button mc-button--secondary" @click="emit('cancel')">Cancel</button>
                    <button ref="confirmButton" type="button" class="mc-button" :class="`mc-button--${confirmVariant}`" @click="emit('confirm')">{{ confirmLabel }}</button>
                </div>
            </section>
        </div>
    </Teleport>
</template>
