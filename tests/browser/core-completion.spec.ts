import { expect, test, captureScreenshot, login, logout, setViewport } from './support';

const viewports = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'tablet', width: 768, height: 1024 },
    { name: 'mobile', width: 390, height: 844 },
] as const;

test('settings update persists after reload and remains usable at responsive viewports @responsive', async ({ page }, testInfo) => {
    const name = `Core QA ${Date.now()}`;
    await login(page, 'admin');
    await page.goto('/admin/settings');
    await expect(page.getByRole('heading', { name: 'Application Settings' })).toBeVisible();
    await expect(page.getByLabel('Application name')).toBeVisible();
    await page.getByLabel('Application name').fill(name);
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('status')).toContainText(/saved/i);
    await page.reload();
    await expect(page.getByLabel('Application name')).toHaveValue(name);

    for (const viewport of viewports) {
        await setViewport(page, viewport.width, viewport.height);
        await expect(page.getByRole('heading', { name: 'Application Settings' })).toBeVisible();
        const width = await page.evaluate(() => ({ viewport: document.documentElement.clientWidth, content: document.documentElement.scrollWidth }));
        expect(width.content, `${viewport.name} settings page should not overflow horizontally`).toBeLessThanOrEqual(width.viewport);
        await captureScreenshot(page, testInfo, `settings-general-${viewport.name}`);
    }

    await logout(page);
    await login(page, 'viewer');
    const denied = await page.evaluate(async () => {
        const response = await fetch('/api/v1/settings/general', {
            method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=') ?? '') },
            body: JSON.stringify({ settings: { application_name: 'Must Not Save' } }),
        });
        return response.status;
    });
    expect(denied).toBe(403);
});

test('RBAC screens grant and revoke a registered ability for an isolated viewer', async ({ page }, testInfo) => {
    const roleName = `Campaign QA ${Date.now()}`;
    await login(page, 'admin');

    await page.goto('/app/permissions');
    await expect(page.getByRole('heading', { name: 'Permissions' })).toBeVisible();
    const materialized = await page.evaluate(async () => {
        await fetch('/sanctum/csrf-cookie', { credentials: 'include' });
        const token = decodeURIComponent(document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=') ?? '');
        const response = await fetch('/api/v1/roles-permissions/permissions', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token },
            body: JSON.stringify({ name: 'view-users' }),
        });
        return response.status;
    });
    expect(materialized).toBe(201);
    await captureScreenshot(page, testInfo, 'rbac-permissions-desktop');

    await page.goto('/app/roles');
    await expect(page.getByRole('heading', { name: 'Roles', exact: true })).toBeVisible();
    await page.getByLabel('Role name').fill(roleName);
    await page.getByRole('button', { name: 'Create role' }).click();
    await expect(page.locator('.mc-toast')).toContainText(/role created/i);
    const roleRow = page.getByRole('row').filter({ hasText: roleName });
    await expect(roleRow).toBeVisible();
    await roleRow.getByRole('button', { name: 'Edit' }).click();
    await expect(page.getByRole('group', { name: 'Registered permissions' })).toBeVisible();
    const permissionLabel = page.locator('label').filter({ hasText: 'view-users' }).first();
    await permissionLabel.locator('input[type="checkbox"]').check();
    await page.getByRole('button', { name: 'Save role' }).click();
    await expect(page.locator('.mc-toast')).toContainText(/role updated/i);
    await captureScreenshot(page, testInfo, 'rbac-role-grant-desktop');

    const viewer = await page.evaluate(async (email) => {
        const response = await fetch(`/api/v1/users?search=${encodeURIComponent(email)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const body = await response.json();
        return { status: response.status, id: body.data?.[0]?.id };
    }, process.env.PLAYWRIGHT_VIEWER_EMAIL!);
    expect(viewer.status).toBe(200);
    expect(viewer.id).toBeTruthy();

    await page.goto(`/app/users/${viewer.id}/roles`);
    await expect(page.getByRole('heading', { name: 'User roles' })).toBeVisible();
    await page.getByLabel(new RegExp(roleName)).check();
    await page.getByRole('button', { name: 'Save assignments' }).click();
    await expect(page.locator('.mc-toast')).toContainText(/assignments saved/i);
    await logout(page);

    await login(page, 'viewer');
    await page.goto('/app/users');
    await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();
    await logout(page);

    await login(page, 'admin');
    await page.goto('/app/roles');
    const refreshedRoleRow = page.getByRole('row').filter({ hasText: roleName });
    await refreshedRoleRow.getByRole('button', { name: 'Edit' }).click();
    await page.locator('label').filter({ hasText: 'view-users' }).first().locator('input[type="checkbox"]').uncheck();
    await page.getByRole('button', { name: 'Save role' }).click();
    await expect(page.locator('.mc-toast')).toContainText(/role updated/i);

    await page.goto(`/app/users/${viewer.id}/roles`);
    await page.getByLabel(new RegExp(roleName)).uncheck();
    await page.getByRole('button', { name: 'Save assignments' }).click();
    await expect(page.locator('.mc-toast')).toContainText(/assignments saved/i);

    await logout(page);
    await login(page, 'viewer');
    const denied = await page.evaluate(async () => (await fetch('/api/v1/users', { credentials: 'same-origin', headers: { Accept: 'application/json' } })).status);
    expect(denied).toBe(403);
});
