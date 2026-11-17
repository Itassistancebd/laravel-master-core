<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { CoreButton, serializeCsv } from '@itassistance-bd/ui';
import { downloadUserImportTemplate, importUsers, previewUserImport, userApiErrorMessage } from '../api';
import type { UserImportPreview, UserImportResult } from '../types';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: []; complete: [result: UserImportResult] }>();
const file = ref<File | null>(null);
const preview = ref<UserImportPreview | null>(null);
const result = ref<UserImportResult | null>(null);
const busy = ref(false);
const error = ref('');
const templateError = ref('');
const canConfirm = computed(() => Boolean(preview.value && file.value && preview.value.summary.valid > 0 && !busy.value));

watch(() => props.open, (open) => {
    if (!open) reset();
});

function reset(): void {
    file.value = null;
    preview.value = null;
    result.value = null;
    busy.value = false;
    error.value = '';
    templateError.value = '';
}

function close(): void { emit('close'); }

async function downloadTemplate(): Promise<void> {
    templateError.value = '';
    try {
        const blob = await downloadUserImportTemplate();
        downloadBlob(blob, 'users-import-template.csv');
    } catch (exception) {
        templateError.value = userApiErrorMessage(exception);
    }
}

async function runPreview(): Promise<void> {
    if (!file.value) return;
    busy.value = true;
    error.value = '';
    result.value = null;
    try {
        preview.value = await previewUserImport(file.value);
    } catch (exception) {
        preview.value = null;
        error.value = userApiErrorMessage(exception);
    } finally {
        busy.value = false;
    }
}

async function confirmImport(): Promise<void> {
    if (!canConfirm.value || !file.value || !preview.value) return;
    busy.value = true;
    error.value = '';
    try {
        result.value = await importUsers(file.value, preview.value.preview_token);
        emit('complete', result.value);
    } catch (exception) {
        error.value = userApiErrorMessage(exception);
    } finally {
        busy.value = false;
    }
}

function downloadErrorReport(): void {
    const rows = result.value?.rows.filter((row) => row.errors.length > 0) ?? [];
    if (rows.length === 0) return;
    const csv = serializeCsv(
        [{ key: 'row', label: 'CSV row' }, { key: 'email', label: 'Email' }, { key: 'status', label: 'Status' }, { key: 'errors', label: 'Errors' }],
        rows.map((row) => ({ row: row.row, email: row.email, status: row.status, errors: row.errors.join('; ') })),
    );
    downloadBlob(new Blob([csv], { type: 'text/csv;charset=utf-8' }), 'users-import-errors.csv');
}

function downloadBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="mc-dialog-backdrop" @click.self="close" @keydown.esc.stop="close">
            <section class="mc-dialog mc-user-import" role="dialog" aria-modal="true" aria-labelledby="mc-user-import-title" aria-describedby="mc-user-import-description">
                <header class="mc-user-import__header">
                    <div>
                        <h2 id="mc-user-import-title" class="mc-dialog__title">Import users</h2>
                        <p id="mc-user-import-description" class="mc-dialog__message">Upload a UTF-8 CSV with the columns name and email. Accounts will be inactive; no setup emails or roles are assigned.</p>
                    </div>
                    <button type="button" class="mc-icon-action" aria-label="Close import dialog" title="Close" @click="close">×</button>
                </header>

                <div class="mc-user-import__actions">
                    <CoreButton variant="secondary" :loading="busy" @click="downloadTemplate">Download CSV template</CoreButton>
                    <label class="mc-user-import__file">
                        <span>CSV file</span>
                        <input type="file" accept=".csv,text/csv" :disabled="busy || Boolean(result)" @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null; preview = null; error = ''">
                    </label>
                    <CoreButton variant="primary" :disabled="!file || busy || Boolean(result)" :loading="busy" @click="runPreview">Preview and validate</CoreButton>
                </div>
                <p v-if="file" class="mc-user-import__filename" role="status">Selected: {{ file.name }}</p>
                <p v-if="templateError" class="mc-user-import__error" role="alert">{{ templateError }}</p>
                <p v-if="error" class="mc-user-import__error" role="alert">{{ error }}</p>

                <section v-if="preview" class="mc-user-import__result" aria-live="polite">
                    <h3>Validation preview</h3>
                    <p>{{ preview.summary.total_rows }} rows · {{ preview.summary.valid }} valid · {{ preview.summary.skipped }} skipped · {{ preview.summary.failed }} failed</p>
                    <p class="mc-user-import__note">Preview expires {{ new Date(preview.expires_at).toLocaleTimeString() }}. Confirming uploads the same file again for server-side revalidation.</p>
                    <div class="mc-user-import__rows">
                        <table>
                            <thead><tr><th scope="col">Row</th><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Status / errors</th></tr></thead>
                            <tbody>
                                <tr v-for="row in preview.rows" :key="row.row">
                                    <td>{{ row.row }}</td><td>{{ row.name }}</td><td>{{ row.email }}</td>
                                    <td><strong>{{ row.status }}</strong><span v-if="row.errors.length"> — {{ row.errors.join('; ') }}</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="mc-user-import__footer">
                        <CoreButton variant="secondary" :disabled="busy || preview.summary.valid === 0" :loading="busy" @click="confirmImport">Confirm import of {{ preview.summary.valid }} valid user{{ preview.summary.valid === 1 ? '' : 's' }}</CoreButton>
                    </div>
                </section>

                <section v-if="result" class="mc-user-import__result" role="status" aria-live="polite">
                    <h3>Import results</h3>
                    <p>{{ result.imported }} imported · {{ result.skipped }} skipped · {{ result.failed }} failed</p>
                    <div v-if="result.rows.some((row) => row.errors.length)" class="mc-user-import__rows">
                        <table>
                            <thead><tr><th scope="col">Row</th><th scope="col">Email</th><th scope="col">Status</th><th scope="col">Errors</th></tr></thead>
                            <tbody><tr v-for="row in result.rows.filter((item) => item.errors.length)" :key="row.row"><td>{{ row.row }}</td><td>{{ row.email }}</td><td>{{ row.status }}</td><td>{{ row.errors.join('; ') }}</td></tr></tbody>
                        </table>
                    </div>
                    <div class="mc-user-import__footer">
                        <CoreButton v-if="result.rows.some((row) => row.errors.length)" variant="secondary" @click="downloadErrorReport">Download error CSV</CoreButton>
                        <CoreButton variant="primary" @click="close">Done</CoreButton>
                    </div>
                </section>

                <footer v-if="!result" class="mc-user-import__footer"><CoreButton variant="secondary" @click="close">Close</CoreButton></footer>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.mc-user-import { width: min(100%, 68rem); max-height: min(90vh, 56rem); overflow: auto; }
.mc-user-import__header, .mc-user-import__actions, .mc-user-import__footer { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.mc-user-import__header { margin-bottom: 1.25rem; }
.mc-user-import__actions { justify-content: flex-start; }
.mc-user-import__file { display: grid; gap: .35rem; min-width: min(100%, 18rem); font-weight: 600; }
.mc-user-import__file input { max-width: 100%; }
.mc-user-import__filename, .mc-user-import__note { margin-top: .75rem; color: #475569; font-size: .875rem; }
.mc-user-import__error { margin-top: .75rem; color: #b91c1c; }
.mc-user-import__result { margin-top: 1.25rem; }
.mc-user-import__result h3 { font-size: 1.125rem; font-weight: 700; }
.mc-user-import__rows { max-height: 22rem; overflow: auto; margin-top: .75rem; border: 1px solid #e2e8f0; border-radius: .5rem; }
.mc-user-import__rows table { width: 100%; border-collapse: collapse; text-align: left; font-size: .875rem; }
.mc-user-import__rows th, .mc-user-import__rows td { padding: .625rem .75rem; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
.mc-user-import__rows th { position: sticky; top: 0; background: #f8fafc; }
.mc-user-import__footer { justify-content: flex-end; margin-top: 1.25rem; }
@media (max-width: 640px) { .mc-user-import__rows { max-width: calc(100vw - 3rem); } }
</style>
