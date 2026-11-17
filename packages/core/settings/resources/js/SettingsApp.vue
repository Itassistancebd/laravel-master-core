<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import {
    CoreButton,
    CoreCheckbox,
    CoreColorInput,
    CoreConfirmDialog,
    CoreField,
    CoreFormSection,
    CoreMultiSelect,
    CoreSearchSelect,
    CoreTextInput,
    CoreToast,
} from '@itassistance-bd/ui';
import type { ApiEnvelope, SettingField, SettingsGroup } from './types';

const props = withDefaults(defineProps<{ apiBase?: string }>(), {
    apiBase: '/api/v1/settings',
});

const groups = ref<Record<string, SettingsGroup>>({});
const activeGroupKey = ref('');
const draft = ref<Record<string, unknown>>({});
const errors = ref<Record<string, string>>({});
const loading = ref(true);
const saving = ref(false);
const resetting = ref(false);
const uploading = ref(false);
const loadError = ref('');
const toast = ref<{ message: string; variant: 'success' | 'error' | 'info' } | null>(null);
const resetDialogOpen = ref(false);

const activeGroup = computed(() => groups.value[activeGroupKey.value] ?? null);
const isDirty = computed(() => {
    if (!activeGroup.value) return false;

    return Object.entries(activeGroup.value.settings).some(([key, field]) => JSON.stringify(draft.value[key]) !== JSON.stringify(field.value));
});

// Each group gets a fresh draft so switching sections never submits stale fields from another group.
watch(activeGroup, (group) => {
    draft.value = group
        ? Object.fromEntries(Object.entries(group.settings).map(([key, field]) => [key, cloneValue(field.value)]))
        : {};
    errors.value = {};
}, { immediate: true });

onMounted(loadSettings);

function cloneValue(value: unknown): unknown {
    return value === undefined ? undefined : structuredClone(value);
}

function showToast(message: string, variant: 'success' | 'error' | 'info' = 'success'): void {
    toast.value = { message, variant };
}

function errorFor(key: string): string {
    return errors.value[key] ?? '';
}

function valueAsText(key: string): string | number | null {
    const value = draft.value[key];
    return typeof value === 'string' || typeof value === 'number' ? value : null;
}

function valueAsArray(key: string): Array<string | number> {
    const value = draft.value[key];
    return Array.isArray(value) ? value.filter((item): item is string | number => typeof item === 'string' || typeof item === 'number') : [];
}

function setValue(key: string, value: unknown): void {
    draft.value[key] = value;
    delete errors.value[key];
}

async function loadSettings(): Promise<void> {
    loading.value = true;
    loadError.value = '';

    try {
        const response = await axios.get<ApiEnvelope<Record<string, SettingsGroup>>>(props.apiBase);
        groups.value = response.data.data;
        activeGroupKey.value = Object.keys(groups.value)[0] ?? '';
    } catch {
        loadError.value = 'Settings could not be loaded. Check your access and try again.';
    } finally {
        loading.value = false;
    }
}

function applyServerErrors(error: unknown): void {
    if (axios.isAxiosError(error)) {
        const apiErrors = error.response?.data?.errors as Record<string, string[]> | undefined;

        if (apiErrors) {
            errors.value = Object.fromEntries(Object.entries(apiErrors).map(([key, messages]) => [
                key.replace(/^settings\./, ''),
                messages[0] ?? 'This value is invalid.',
            ]));
            showToast('Review the highlighted fields.', 'error');
            return;
        }
    }

    showToast('The request could not be completed. Try again.', 'error');
}

async function saveGroup(): Promise<void> {
    if (!activeGroup.value || saving.value) return;

    saving.value = true;
    errors.value = {};

    try {
        const response = await axios.put<ApiEnvelope<SettingsGroup>>(
            `${props.apiBase}/${encodeURIComponent(activeGroup.value.key)}`,
            { settings: draft.value },
        );
        groups.value[activeGroup.value.key] = response.data.data;
        showToast(`${activeGroup.value.label} settings saved.`);
    } catch (error) {
        applyServerErrors(error);
    } finally {
        saving.value = false;
    }
}

async function resetGroup(): Promise<void> {
    if (!activeGroup.value || resetting.value) return;

    resetting.value = true;

    try {
        const response = await axios.post<ApiEnvelope<SettingsGroup>>(
            `${props.apiBase}/${encodeURIComponent(activeGroup.value.key)}/reset`,
        );
        groups.value[activeGroup.value.key] = response.data.data;
        showToast(`${activeGroup.value.label} settings reset to their defaults.`);
    } catch (error) {
        applyServerErrors(error);
    } finally {
        resetting.value = false;
        resetDialogOpen.value = false;
    }
}

async function uploadBrandAsset(field: SettingField, event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file || uploading.value) return;

    uploading.value = true;
    const form = new FormData();
    form.append('asset_type', field.key === 'logo_path' ? 'logo' : 'favicon');
    form.append('file', file);

    try {
        const response = await axios.post<ApiEnvelope<SettingsGroup>>(`${props.apiBase}/branding/assets`, form);
        groups.value.branding = response.data.data;
        showToast(`${field.label} uploaded.`);
    } catch (error) {
        applyServerErrors(error);
    } finally {
        uploading.value = false;
        input.value = '';
    }
}

function isChecked(key: string): boolean {
    return draft.value[key] === true;
}
</script>

<template>
    <main class="mx-auto min-h-screen max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <header class="mb-8 border-b border-slate-200 pb-6">
            <p class="text-sm font-semibold uppercase tracking-wide text-blue-700">Master Core</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-950">Application Settings</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Manage application-wide preferences, branding, and display formats.</p>
        </header>

        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-slate-600" role="status" aria-live="polite">
            Loading settings…
        </div>

        <div v-else-if="loadError" class="rounded-xl border border-red-200 bg-red-50 p-6 text-red-800" role="alert">
            <p>{{ loadError }}</p>
            <CoreButton class="mt-4" variant="secondary" @click="loadSettings">Try again</CoreButton>
        </div>

        <div v-else-if="Object.keys(groups).length === 0" class="rounded-xl border border-slate-200 bg-white p-8 text-center" role="status">
            <h2 class="text-lg font-semibold text-slate-900">No settings are available</h2>
            <p class="mt-2 text-sm text-slate-600">The Settings package has no registered setting groups.</p>
        </div>

        <div v-else class="grid gap-6 lg:grid-cols-[15rem_minmax(0,1fr)]">
            <nav role="tablist" aria-label="Settings sections" class="flex gap-2 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible">
                <button
                    v-for="group in Object.values(groups)"
                    :key="group.key"
                    type="button"
                    role="tab"
                    class="shrink-0 rounded-lg px-4 py-3 text-left text-sm font-medium transition"
                    :class="activeGroupKey === group.key ? 'bg-blue-700 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200 hover:bg-slate-100'"
                    :aria-selected="activeGroupKey === group.key"
                    :aria-controls="`settings-panel-${group.key}`"
                    @click="activeGroupKey = group.key"
                >
                    {{ group.label }}
                </button>
            </nav>

            <section v-if="activeGroup" :id="`settings-panel-${activeGroup.key}`" role="tabpanel" class="min-w-0 rounded-xl border border-slate-200 bg-white shadow-sm">
                <form @submit.prevent="saveGroup">
                    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-7">
                        <div>
                            <h2 class="text-xl font-semibold text-slate-950">{{ activeGroup.label }}</h2>
                            <p class="mt-1 text-sm text-slate-600">Changes apply to this application.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <CoreButton variant="secondary" :disabled="saving || resetting || uploading" @click="resetDialogOpen = true">
                                Reset to defaults
                            </CoreButton>
                            <CoreButton type="submit" variant="primary" :loading="saving" :disabled="!isDirty || uploading">
                                Save changes
                            </CoreButton>
                        </div>
                    </div>

                    <div class="px-5 py-6 sm:px-7">
                      <CoreFormSection number="1" title="Settings" description="Edit the options for the selected settings group.">
                        <CoreField
                          v-for="field in Object.values(activeGroup.settings)"
                          :key="field.key"
                          :label="field.label"
                          :help="field.description ?? ''"
                          :error="errorFor(field.key)"
                          :required="field.required"
                          :class="field.type === 'textarea' || field.type.startsWith('searchable') ? 'sm:col-span-2' : ''"
                        >
                            <template #default="{ id, describedBy }">
                                <CoreTextInput
                                    v-if="['text', 'email', 'number'].includes(field.type)"
                                    :id="id"
                                    :type="field.type as 'text' | 'email' | 'number'"
                                    :model-value="valueAsText(field.key)"
                                    :aria-describedby="describedBy"
                                    :invalid="Boolean(errorFor(field.key))"
                                    @update:model-value="setValue(field.key, field.type === 'number' ? Number($event) : $event)"
                                />
                                <textarea
                                    v-else-if="field.type === 'textarea'"
                                    :id="id"
                                    :value="valueAsText(field.key) ?? ''"
                                    class="mc-control min-h-28 resize-y"
                                    :class="{ 'mc-control--invalid': Boolean(errorFor(field.key)) }"
                                    :aria-describedby="describedBy"
                                    :aria-invalid="Boolean(errorFor(field.key)) || undefined"
                                    @input="setValue(field.key, ($event.target as HTMLTextAreaElement).value)"
                                />
                                <CoreSearchSelect
                                    v-else-if="field.type === 'searchable_select'"
                                    :id="id"
                                    :model-value="valueAsText(field.key)"
                                    :options="field.options ?? []"
                                    :described-by="describedBy"
                                    :invalid="Boolean(errorFor(field.key))"
                                    @update:model-value="setValue(field.key, $event)"
                                />
                                <CoreMultiSelect
                                    v-else-if="field.type === 'searchable_multiselect'"
                                    :id="id"
                                    :model-value="valueAsArray(field.key)"
                                    :options="field.options ?? []"
                                    :described-by="describedBy"
                                    :invalid="Boolean(errorFor(field.key))"
                                    @update:model-value="setValue(field.key, $event)"
                                />
                                <CoreColorInput
                                    v-else-if="field.type === 'color'"
                                    :id="id"
                                    :model-value="String(draft[field.key] ?? '#000000')"
                                    :aria-describedby="describedBy"
                                    @update:model-value="setValue(field.key, $event)"
                                />
                                <CoreCheckbox
                                    v-else-if="field.type === 'boolean'"
                                    :id="id"
                                    :model-value="isChecked(field.key)"
                                    :aria-describedby="describedBy"
                                    @update:model-value="setValue(field.key, $event)"
                                />
                                <template v-else-if="field.type === 'image_path'">
                                    <p v-if="draft[field.key]" class="mb-2 break-all text-sm text-slate-600">Current file: {{ draft[field.key] }}</p>
                                    <input
                                        :id="id"
                                        type="file"
                                        accept="image/png,image/jpeg,image/webp"
                                        class="mc-control file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-3 file:py-1"
                                        :aria-describedby="describedBy"
                                        :disabled="uploading"
                                        @change="uploadBrandAsset(field, $event)"
                                    >
                                </template>
                                <p v-else class="text-sm text-red-700">Unsupported setting control: {{ field.type }}</p>
                            </template>
                        </CoreField>
                      </CoreFormSection>
                    </div>
                </form>
            </section>
        </div>

        <CoreToast
            v-if="toast"
            class="fixed bottom-5 right-5 z-50"
            :message="toast.message"
            :variant="toast.variant"
            @dismiss="toast = null"
        />

        <CoreConfirmDialog
            :open="resetDialogOpen"
            title="Reset this settings group?"
            :message="`Saved overrides for ${activeGroup?.label ?? 'this group'} will be removed. Values will return to their defaults.`"
            confirm-label="Reset settings"
            :destructive="true"
            @cancel="resetDialogOpen = false"
            @confirm="resetGroup"
        />
    </main>
</template>
