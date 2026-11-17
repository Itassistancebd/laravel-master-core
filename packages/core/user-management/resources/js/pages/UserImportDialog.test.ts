import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserImportDialog from './UserImportDialog.vue';

const api = vi.hoisted(() => ({
    downloadUserImportTemplate: vi.fn(),
    importUsers: vi.fn(),
    previewUserImport: vi.fn(),
    userApiErrorMessage: vi.fn((error: unknown) => error instanceof Error ? error.message : 'Request failed.'),
}));

vi.mock('../api', () => api);

const preview = {
    preview_token: 'safe-token',
    expires_at: '2026-11-16T10:00:00Z',
    summary: { total_rows: 2, valid: 1, skipped: 1, failed: 0 },
    rows: [
        { row: 2, name: 'Ada', email: 'ada@example.test', status: 'valid' as const, errors: [] },
        { row: 3, name: 'Existing', email: 'old@example.test', status: 'skipped' as const, errors: ['Email already exists.'] },
    ],
};

async function clickButton(label: string): Promise<void> {
    const button = Array.from(document.body.querySelectorAll('button')).find((item) => item.textContent?.includes(label));
    if (!button) throw new Error(`Could not find button: ${label}`);
    button.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await flushPromises();
}

function chooseFile(file: File): void {
    const input = document.body.querySelector<HTMLInputElement>('input[type="file"]');
    if (!input) throw new Error('Could not find CSV file input.');
    Object.defineProperty(input, 'files', { value: [file], configurable: true });
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

describe('UserImportDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        document.body.innerHTML = '';
    });

    it('previews, confirms, reports counts and offers an error CSV', async () => {
        const file = new File(['name,email\nAda,ada@example.test'], 'users.csv', { type: 'text/csv' });
        api.previewUserImport.mockResolvedValue(preview);
        api.importUsers.mockResolvedValue({ imported: 1, skipped: 1, failed: 0, rows: preview.rows.map((row) => ({ ...row, status: row.status === 'valid' ? 'imported' as const : row.status })) });
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
        Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:test') });
        Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });
        mount(UserImportDialog, { props: { open: true } });
        chooseFile(file);
        await flushPromises();
        await clickButton('Preview and validate');
        await flushPromises();

        expect(api.previewUserImport).toHaveBeenCalledWith(file);
        expect(document.body.textContent).toContain('1 valid');
        await clickButton('Confirm import');
        expect(api.importUsers).toHaveBeenCalledWith(file, 'safe-token');
        expect(document.body.textContent).toContain('1 imported');
        expect(document.body.textContent).toContain('inactive');
        await clickButton('Download error CSV');
        expect(URL.createObjectURL).toHaveBeenCalled();
    });

    it('shows preview errors and does not enable confirmation without valid rows', async () => {
        const file = new File(['name,email\n,invalid'], 'users.csv', { type: 'text/csv' });
        api.previewUserImport.mockResolvedValue({ ...preview, summary: { total_rows: 1, valid: 0, skipped: 0, failed: 1 } });
        mount(UserImportDialog, { props: { open: true } });
        chooseFile(file);
        await flushPromises();
        await clickButton('Preview and validate');
        const confirm = Array.from(document.body.querySelectorAll('button')).find((button) => button.textContent?.includes('Confirm import'));
        expect(confirm?.disabled).toBe(true);
    });

    it('downloads the server CSV template and reports template failures accessibly', async () => {
        api.downloadUserImportTemplate.mockResolvedValue(new Blob(['\uFEFF"name","email"\r\n'], { type: 'text/csv' }));
        const downloads: string[] = [];
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) { downloads.push(this.download); });
        Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:template') });
        Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });
        mount(UserImportDialog, { props: { open: true } });

        await clickButton('Download CSV template');
        expect(api.downloadUserImportTemplate).toHaveBeenCalledTimes(1);
        expect(downloads).toContain('users-import-template.csv');

        api.downloadUserImportTemplate.mockRejectedValueOnce(new Error('Template unavailable.'));
        await clickButton('Download CSV template');
        expect(document.querySelector('[role="alert"]')?.textContent).toContain('Template unavailable.');
    });
});
