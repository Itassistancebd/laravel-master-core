import { expect, test as base, type Page, type TestInfo } from '@playwright/test';
import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

type Diagnostics = {
    consoleErrors: Array<{ message: string; source?: string }>;
    httpErrors: Array<{ status: number; path: string }>;
    pageErrors: string[];
};

export const test = base.extend<{ browserDiagnostics: void }>({
    browserDiagnostics: [async ({ page }, use, testInfo) => {
        const diagnostics: Diagnostics = { consoleErrors: [], httpErrors: [], pageErrors: [] };
        page.on('console', (message) => {
            if (message.type() === 'error') {
                const source = safePath(message.location().url);
                diagnostics.consoleErrors.push({ message: redact(message.text()), ...(source ? { source } : {}) });
            }
        });
        page.on('response', (response) => {
            if (response.status() >= 400) diagnostics.httpErrors.push({ status: response.status(), path: safePath(response.url()) ?? '[local response]' });
        });
        page.on('pageerror', (error) => diagnostics.pageErrors.push(redact(error.message)));
        await use();
        const diagnosticsPath = testInfo.outputPath('browser-diagnostics.json');
        await writeFile(diagnosticsPath, JSON.stringify(diagnostics, null, 2), 'utf8');
        await testInfo.attach('browser-diagnostics.json', {
            path: diagnosticsPath,
            contentType: 'application/json',
        });
    }, { auto: true }],
});

export { expect };

export async function login(page: Page, actor: 'admin' | 'viewer' = 'admin'): Promise<void> {
    const email = process.env[actor === 'admin' ? 'PLAYWRIGHT_ADMIN_EMAIL' : 'PLAYWRIGHT_VIEWER_EMAIL'];
    const password = process.env[actor === 'admin' ? 'PLAYWRIGHT_ADMIN_PASSWORD' : 'PLAYWRIGHT_VIEWER_PASSWORD'];
    if (!email || !password) throw new Error('Isolated Playwright credentials were not provided to the browser worker.');

    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/app(?:\/|$)/);
}

export async function logout(page: Page): Promise<void> {
    await page.evaluate(async () => {
        await fetch('/sanctum/csrf-cookie', { credentials: 'include' });
        const token = decodeURIComponent(document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=') ?? '');
        await fetch('/logout', { method: 'POST', credentials: 'include', headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' } });
    });
    await page.goto('/login');
}

export async function navigateAuthenticated(page: Page, path: string, actor: 'admin' | 'viewer' = 'admin'): Promise<void> {
    await login(page, actor);
    await page.goto(path);
}

export async function navigateToDashboard(page: Page, actor: 'admin' | 'viewer' = 'admin'): Promise<void> {
    await navigateAuthenticated(page, '/app', actor);
}

export async function navigateToUsers(page: Page, actor: 'admin' | 'viewer' = 'admin'): Promise<void> {
    await navigateAuthenticated(page, '/app/users', actor);
}

export async function navigateToRoles(page: Page, actor: 'admin' | 'viewer' = 'admin'): Promise<void> {
    await navigateAuthenticated(page, '/app/roles', actor);
}

export async function navigateToPermissions(page: Page, actor: 'admin' | 'viewer' = 'admin'): Promise<void> {
    await navigateAuthenticated(page, '/app/permissions', actor);
}

export async function verifyDashboard(page: Page): Promise<void> {
    await expect(page).toHaveURL(/\/app(?:\/|$)/);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
}

export async function verifyTableHasRows(page: Page): Promise<void> {
    await expect(dataTable(page).getByRole('row').nth(1)).toBeVisible();
}

export async function captureScreenshot(page: Page, testInfo: TestInfo, name: string): Promise<string> {
    const directory = process.env.E2E_ARTIFACT_DIR ?? join(testInfo.outputDir, 'screenshots');
    await mkdir(directory, { recursive: true });
    const path = join(directory, `${name}.png`);
    const bytes = await page.screenshot({ path, fullPage: false, animations: 'disabled' });
    await testInfo.attach(`${name}.png`, { body: bytes, contentType: 'image/png' });
    return path;
}

export async function fillFormField(page: Page, label: string, value: string): Promise<void> {
    await page.getByLabel(label, { exact: true }).fill(value);
}

export function dataTable(page: Page) {
    return page.getByRole('region', { name: 'Data table' });
}

export function dialog(page: Page, name: string) {
    return page.getByRole('dialog', { name });
}

export async function setViewport(page: Page, width: number, height: number): Promise<void> {
    await page.setViewportSize({ width, height });
}

function redact(value: string): string {
    for (const key of ['PLAYWRIGHT_ADMIN_PASSWORD', 'PLAYWRIGHT_VIEWER_PASSWORD', 'PLAYWRIGHT_ADMIN_EMAIL', 'PLAYWRIGHT_VIEWER_EMAIL']) {
        const secret = process.env[key];
        if (secret) value = value.replaceAll(secret, '[REDACTED]');
    }
    return value.replace(/https?:\/\/[^\s)]+/g, (url) => {
        try {
            const parsed = new URL(url);
            return `${parsed.origin}${parsed.pathname}`;
        } catch { return '[URL]'; }
    });
}

function safePath(value: string): string | undefined {
    if (!value) return undefined;
    try {
        const url = new URL(value);
        return `${url.pathname}${url.hash ? '#[fragment]' : ''}`;
    } catch { return undefined; }
}
