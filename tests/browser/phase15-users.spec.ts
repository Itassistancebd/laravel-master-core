import { expect, test, captureScreenshot, dataTable, dialog, login, logout, navigateToDashboard, navigateToUsers, navigateAuthenticated, setViewport, verifyDashboard, verifyTableHasRows } from './support';

const viewports = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'tablet', width: 768, height: 1024 },
    { name: 'mobile', width: 390, height: 844 },
] as const;

test('dashboard and user import journey work at all responsive viewports @responsive', async ({ page }, testInfo) => {
    const notificationApiFailures: number[] = [];
    page.on('response', (response) => {
        if (new URL(response.url()).pathname === '/api/v1/notifications' && response.status() >= 400) {
            notificationApiFailures.push(response.status());
        }
    });

    for (const viewport of viewports) {
        await setViewport(page, viewport.width, viewport.height);
        await navigateToDashboard(page);
        await verifyDashboard(page);
        await page.goto('/app/users');
        await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();
        await verifyTableHasRows(page);
        await expect(page.getByRole('button', { name: 'Export Users' })).toBeEnabled();
        await expect(page.getByRole('button', { name: 'Import Users' })).toBeEnabled();
        await captureScreenshot(page, testInfo, `users-list-${viewport.name}`);

        await page.getByRole('button', { name: 'Import Users' }).click();
        const importDialog = dialog(page, 'Import users');
        await expect(importDialog).toBeVisible();
        await captureScreenshot(page, testInfo, `users-import-dialog-${viewport.name}`);

        const templateDownload = page.waitForEvent('download');
        await importDialog.getByRole('button', { name: 'Download CSV template' }).click();
        const template = await templateDownload;
        expect(template.suggestedFilename()).toBe('users-import-template.csv');
        expect((await downloadBytes(template)).toString('utf8')).toBe('\uFEFF"name","email"\r\n');

        const suffix = `${viewport.name}-${Date.now()}`;
        const validEmail = `phase15-import-${suffix}@example.test`;
        const duplicateEmail = `phase15-duplicate-${suffix}@example.test`;
        const existingEmail = process.env.PLAYWRIGHT_ADMIN_EMAIL!;
        const csv = [
            'name,email',
            `বাংলা QA ${viewport.name},${validEmail}`,
            `Duplicate first,${duplicateEmail}`,
            `Duplicate second,${duplicateEmail}`,
            `Existing QA,${existingEmail}`,
            'Invalid email,not-an-email',
            `,missing-name-${suffix}@example.test`,
        ].join('\r\n');
        await importDialog.getByLabel('CSV file').setInputFiles({ name: 'phase15-users.csv', mimeType: 'text/csv', buffer: Buffer.from(`\uFEFF${csv}`, 'utf8') });
        await importDialog.getByRole('button', { name: 'Preview and validate' }).click();
        await expect(importDialog.getByRole('heading', { name: 'Validation preview' })).toBeVisible();
        await expect(importDialog.getByText(/6 rows/)).toBeVisible();
        await expect(importDialog.getByText(validEmail)).toBeVisible();
        await expect(importDialog.getByText(/duplicate/i).first()).toBeVisible();
        await expect(importDialog.getByText(/invalid email/i).first()).toBeVisible();
        await captureScreenshot(page, testInfo, `users-import-preview-${viewport.name}`);

        const confirm = importDialog.getByRole('button', { name: /Confirm import of 2 valid users/ });
        await expect(confirm).toBeEnabled();
        await confirm.click();
        await expect(importDialog.getByRole('heading', { name: 'Import results' })).toBeVisible();
        await expect(importDialog.getByText(/2 imported/)).toBeVisible();
        await expect(importDialog.getByText(/2 skipped/).last()).toBeVisible();
        await expect(importDialog.getByText(/2 failed/).last()).toBeVisible();
        await importDialog.evaluate((element) => { element.scrollTop = element.scrollHeight; });
        await captureScreenshot(page, testInfo, `users-import-results-${viewport.name}`);

        const errorReportDownload = page.waitForEvent('download');
        await importDialog.getByRole('button', { name: 'Download error CSV' }).click();
        const errorReport = await errorReportDownload;
        expect(errorReport.suggestedFilename()).toBe('users-import-errors.csv');
        expect((await downloadBytes(errorReport)).toString('utf8')).toContain('CSV row');

        const importedPayload = await page.evaluate(async (email) => {
            const response = await fetch(`/api/v1/users?search=${encodeURIComponent(email)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            return { status: response.status, body: await response.json() };
        }, validEmail);
        expect(importedPayload.status).toBe(200);
        expect(importedPayload.body.data[0].email).toBe(validEmail);
        expect(importedPayload.body.data[0].is_active).toBe(false);
        expect(importedPayload.body.data[0]).not.toHaveProperty('password');

        await importDialog.getByRole('button', { name: 'Done' }).click();
        await expect(importDialog).toBeHidden();
        await logout(page);
    }

    expect(notificationApiFailures).toEqual([]);
});

test('server export returns every filtered page and protects formula-like data', async ({ page }, testInfo) => {
    for (const viewport of viewports) {
        await setViewport(page, viewport.width, viewport.height);
        await navigateAuthenticated(page, '/app/users');
        await page.getByLabel('Search users').fill('Phase15 Export QA');
        await page.getByRole('combobox', { name: 'Account status' }).selectOption('active');
        await page.getByRole('combobox', { name: 'Email verification' }).selectOption('verified');
        await expect(dataTable(page).getByText(/Showing 1.*25 of 36/)).toBeVisible();
        await expect(dataTable(page).getByText('Phase15 Export QA 01')).toBeVisible();
        await captureScreenshot(page, testInfo, `users-export-filtered-before-download-${viewport.name}`);

        const downloadEvent = page.waitForEvent('download');
        await page.getByRole('button', { name: 'Export Users' }).click();
        const download = await downloadEvent;
        expect(download.suggestedFilename()).toBe('users.csv');
        const csv = (await downloadBytes(download)).toString('utf8');
        expect(csv.startsWith('\uFEFF')).toBeTruthy();
        const lines = csv.trimEnd().split(/\r?\n/);
        expect(lines).toHaveLength(37);
        expect(lines[0]).toMatch(/(^|,)name(,|$)/);
        expect(lines[0]).toMatch(/(^|,)email(,|$)/);
        expect(lines[0].toLowerCase()).not.toContain('password');
        expect(lines[0].toLowerCase()).not.toContain('token');
        expect(csv).toContain("'=1+1 Phase15 Export QA 01");
        expect(csv).not.toContain('phase15-export-inactive@example.test');
        expect(csv).not.toContain('phase15-export-unverified@example.test');
        await expect(page.getByRole('status').filter({ hasText: 'CSV downloaded' })).toBeVisible();
        await captureScreenshot(page, testInfo, `users-export-filtered-downloaded-${viewport.name}`);

        await dataTable(page).getByRole('button', { name: 'Next page' }).click();
        await expect(dataTable(page).getByText(/Showing 26.*36 of 36/)).toBeVisible();
        await logout(page);
    }
});

test('ordinary authenticated user is denied import and export without grants', async ({ page }) => {
    await login(page, 'viewer');
    await page.goto('/app/users');
    await expect(page.getByRole('button', { name: 'Export Users' })).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Import Users' })).toBeDisabled();
    const statuses = await page.evaluate(async () => {
        const [exportResponse, templateResponse] = await Promise.all([
            fetch('/api/v1/users/export.csv', { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
            fetch('/api/v1/users/import-template.csv', { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
        ]);
        return [exportResponse.status, templateResponse.status];
    });
    expect(statuses).toEqual([403, 403]);
});

async function downloadBytes(download: import('@playwright/test').Download): Promise<Buffer> {
    const stream = await download.createReadStream();
    if (!stream) throw new Error('Browser download did not provide a readable stream.');
    const chunks: Buffer[] = [];
    for await (const chunk of stream) chunks.push(Buffer.from(chunk));
    return Buffer.concat(chunks);
}
