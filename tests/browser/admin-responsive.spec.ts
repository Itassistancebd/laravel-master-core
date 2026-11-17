import { expect, test, captureScreenshot, login, setViewport } from './support';

const viewports = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'tablet', width: 768, height: 1024 },
    { name: 'mobile', width: 390, height: 844 },
] as const;

const pages = [
    { name: 'dashboard', path: '/app', heading: 'Good morning|Good afternoon|Good evening' },
    { name: 'users-list', path: '/app/users', heading: 'Users' },
    { name: 'users-create', path: '/app/users/create', heading: 'Create user' },
    { name: 'users-trash', path: '/app/users/trash', heading: 'Trash' },
    { name: 'users-details', path: '/app/users/1', heading: 'User details' },
    { name: 'users-edit', path: '/app/users/1/edit', heading: 'Edit user' },
    { name: 'roles', path: '/app/roles', heading: 'Roles' },
    { name: 'permissions', path: '/app/permissions', heading: 'Permissions' },
    { name: 'user-roles', path: '/app/users/2/roles', heading: 'User roles' },
    { name: 'access-denied', path: '/app/access-denied', heading: 'Access unavailable' },
    { name: 'modules', path: '/app/modules', heading: 'Core Modules' },
    { name: 'system-health', path: '/app/system-health', heading: 'System Health & Readiness' },
    { name: 'audit-logs', path: '/app/audit-logs', heading: 'Audit Logs' },
    { name: 'notifications', path: '/app/notifications', heading: 'Notification Center' },
    { name: 'account-security', path: '/app/account-security', heading: 'Signed-in sessions' },
    { name: 'settings', path: '/admin/settings', heading: 'Application Settings' },
] as const;

test('every registered Admin screen renders at desktop, tablet and mobile @responsive', async ({ page }, testInfo) => {
    test.setTimeout(300_000);
    const pageErrors: string[] = [];
    const consoleErrors: string[] = [];
    page.on('pageerror', (error) => pageErrors.push(`${page.url()}: ${error.message}`));
    page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(`${page.url()}: ${message.text()}`); });
    await login(page, 'admin');

    for (const viewport of viewports) {
        await setViewport(page, viewport.width, viewport.height);
        for (const entry of pages) {
            const apiFailures: Array<{ status: number; path: string }> = [];
            const onResponse = (response: import('@playwright/test').Response) => {
                const url = new URL(response.url());
                if (url.pathname.startsWith('/api/') && response.status() >= 400) {
                    apiFailures.push({ status: response.status(), path: url.pathname });
                }
            };
            page.on('response', onResponse);
            await page.goto(entry.path);
            await expect(page).toHaveURL(new RegExp(`${entry.path.replace(/[.*+?^${}()|[\\]\\]/g, '\\$&')}(?:$|[?#])`));
            const main = page.locator('.mc-admin__content, main.auth-app').first();
            await expect(main).toBeVisible();
            if (entry.name === 'dashboard') {
                await expect(main.getByRole('heading', { level: 1 })).toBeVisible();
                await expect(main.getByRole('region', { name: 'Workspace metrics' })).toBeVisible();
            } else {
                await expect(main.getByText(entry.heading, { exact: false }).first()).toBeVisible();
            }
            await page.waitForLoadState('networkidle');

            const geometry = await page.evaluate(() => ({
                viewport: window.innerWidth,
                document: document.documentElement.scrollWidth,
                body: document.body.scrollWidth,
                overflowers: Array.from(document.querySelectorAll<HTMLElement>('body *'))
                    .map((element) => ({ element, rect: element.getBoundingClientRect() }))
                    .filter(({ element, rect }) => rect.width > 0 && (rect.right > window.innerWidth + 1 || element.scrollWidth > element.clientWidth + 2))
                    .map(({ element, rect }) => ({ tag: element.tagName, className: String(element.className).slice(0, 90), left: Math.round(rect.left), right: Math.round(rect.right), width: Math.round(rect.width), scroll: element.scrollWidth, client: element.clientWidth })),
                main: (() => {
                    const element = document.querySelector<HTMLElement>('.mc-admin__content, main.auth-app');
                    const rect = element?.getBoundingClientRect();
                    return rect ? { left: Math.round(rect.left), right: Math.round(rect.right), width: Math.round(rect.width) } : null;
                })(),
                navToggle: (() => {
                    const element = document.querySelector<HTMLElement>('.mc-admin__menu-button');
                    return element ? getComputedStyle(element).display !== 'none' : true;
                })(),
            }));
            expect(geometry.document, `${entry.name}/${viewport.name} page has no horizontal overflow: ${JSON.stringify(geometry.overflowers)}`).toBeLessThanOrEqual(geometry.viewport);
            expect(geometry.body, `${entry.name}/${viewport.name} body has no horizontal overflow`).toBeLessThanOrEqual(geometry.viewport);
            expect(geometry.main?.width, `${entry.name}/${viewport.name} primary content has width`).toBeGreaterThan(0);
            if (viewport.width <= 760) expect(geometry.navToggle, `${entry.name}/${viewport.name} has a visible navigation toggle`).toBe(true);

            await captureScreenshot(page, testInfo, `admin-${entry.name}-${viewport.name}`);
            page.off('response', onResponse);
            const unexpectedFailures = apiFailures.filter(({ status, path }) =>
                status !== 401 && status !== 403 && !(entry.name === 'account-security' && status === 501 && path.endsWith('/sessions')));
            expect(unexpectedFailures, `${entry.name}/${viewport.name} unexpected application API failures`).toEqual([]);
        }
    }

    expect(pageErrors, 'No uncaught JavaScript errors on Admin routes').toEqual([]);
    const unexpectedConsoleErrors = consoleErrors.filter((message) =>
        !/Failed to load resource: the server responded with a status of (401|403) \((Unauthorized|Forbidden)\)/.test(message)
        && !(/Failed to load resource: the server responded with a status of 501 \(Not Implemented\)/.test(message)
            && message.includes('/app/account-security:')));
    expect(unexpectedConsoleErrors, 'No unexpected browser console errors on Admin routes').toEqual([]);
});

test('responsive Admin navigation does not expose the removed Categories feature @responsive', async ({ page }) => {
    test.setTimeout(180_000);
    await login(page, 'admin');
    for (const viewport of viewports) {
        await setViewport(page, viewport.width, viewport.height);
        await page.goto('/app');

        if (viewport.width <= 760) {
            await page.getByRole('button', { name: 'Expand navigation' }).click();
            await expect(page.getByRole('navigation', { name: 'Main navigation' })).toBeVisible();
            await expect(page.getByRole('link', { name: 'Categories' })).toHaveCount(0);
            await page.getByRole('button', { name: 'User Management' }).click();
            await page.getByRole('link', { name: 'Users', exact: true }).click();
            await expect(page).toHaveURL(/\/app\/users$/);
        } else {
            await page.getByRole('button', { name: 'Search pages' }).click();
            const search = page.locator('.mc-admin__search-field input');
            await search.fill('Categories');
            await expect(page.getByRole('link', { name: 'Categories' })).toHaveCount(0);
            await search.fill('Users');
            await page.getByRole('link', { name: 'Users', exact: true }).last().click();
            await expect(page).toHaveURL(/\/app\/users$/);
        }
    }
});
