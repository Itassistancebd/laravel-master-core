import { expect, test, captureScreenshot, dataTable, login, logout, navigateAuthenticated, setViewport } from './support';

const viewports = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'tablet', width: 768, height: 1024 },
    { name: 'mobile', width: 390, height: 844 },
] as const;

test('Categories engine resource supports CRUD, query behavior, RBAC and responsive layouts @responsive', async ({ page }, testInfo) => {
    test.setTimeout(180_000);
    const pageErrors: string[] = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    for (const viewport of viewports) {
        await setViewport(page, viewport.width, viewport.height);
        await navigateAuthenticated(page, '/app/categories');
        await expect(page.getByRole('heading', { level: 1, name: 'Categories' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Categories' })).toBeVisible();
        await expect(dataTable(page).getByText('QA Category 01')).toBeVisible();
        await expect(dataTable(page).getByText(/Showing 1.*25 of 31/)).toBeVisible();
        await dataTable(page).getByRole('button', { name: 'Next page' }).click();
        await expect(dataTable(page).getByText('QA Category 26')).toBeVisible();
        await dataTable(page).getByRole('button', { name: 'Previous page' }).click();
        await captureScreenshot(page, testInfo, `categories-list-${viewport.name}`);

        const status = page.getByRole('combobox', { name: 'Status' });
        await status.selectOption('inactive');
        await expect(dataTable(page).getByText('QA Category 02')).toBeVisible();
        await expect(dataTable(page).getByText('QA Category 01')).toHaveCount(0);
        await status.selectOption('active');

        const unique = `${viewport.name}-${Date.now()}`;
        const originalName = `Pilot ${unique}`;
        await page.getByRole('button', { name: 'Add Category' }).click();
        await page.getByRole('textbox', { name: 'Name' }).fill(originalName);
        await page.getByRole('textbox', { name: 'Description' }).fill('CRUD Engine browser acceptance.');
        await captureScreenshot(page, testInfo, `categories-create-${viewport.name}`);
        await page.getByRole('button', { name: 'Save' }).click();
        await expect(page.getByText('Category created.')).toBeVisible();

        const search = page.getByRole('textbox', { name: 'Search' });
        await search.fill(originalName);
        await page.getByRole('button', { name: 'Search', exact: true }).click();
        const row = dataTable(page).getByRole('row').filter({ hasText: originalName });
        await expect(row).toBeVisible();
        await expect(row).toContainText(`pilot-${unique}`);
        await row.getByRole('button', { name: 'View' }).click();
        await expect(page.getByRole('heading', { name: 'Categories details' })).toBeVisible();
        await expect(page.getByRole('region', { name: 'Record details' }).getByText('CRUD Engine browser acceptance.')).toBeVisible();
        await captureScreenshot(page, testInfo, `categories-view-${viewport.name}`);
        await page.getByRole('button', { name: 'Close details' }).click();

        await row.getByRole('button', { name: 'Edit' }).click();
        await page.getByRole('textbox', { name: 'Name' }).fill(`${originalName} Edited`);
        await page.getByRole('button', { name: 'Save' }).click();
        await expect(page.getByText('Category updated.')).toBeVisible();
        await expect(dataTable(page).getByText(`${originalName} Edited`)).toBeVisible();

        const editedRow = dataTable(page).getByRole('row').filter({ hasText: `${originalName} Edited` });
        await editedRow.getByRole('button', { name: 'Delete' }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog).toContainText('Delete this category?');
        await dialog.getByRole('button', { name: 'Delete', exact: true }).click();
        await expect(page.getByText('Category deleted.')).toBeVisible();
        await expect(dataTable(page).getByText(`${originalName} Edited`)).toHaveCount(0);

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
        expect(overflow, `${viewport.name} has no horizontal page overflow`).toBe(false);
        await logout(page);
    }

    await login(page, 'viewer');
    await expect(page.getByRole('link', { name: 'Categories' })).toHaveCount(0);
    await page.goto('/app/categories');
    await expect(page).toHaveURL(/\/app\/access-denied/);
    const denied = await page.evaluate(async () => (await fetch('/api/v1/core/categories', { credentials: 'same-origin', headers: { Accept: 'application/json' } })).status);
    expect(denied).toBe(403);
    expect(pageErrors).toEqual([]);
});
