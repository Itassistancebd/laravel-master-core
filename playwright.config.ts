import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/browser',
    testMatch: '**/*.spec.ts',
    fullyParallel: false,
    workers: 1,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    outputDir: process.env.PLAYWRIGHT_OUTPUT_DIR ?? './test-results',
    preserveOutput: 'always',
    reporter: [
        ['list'],
        ['html', { outputFolder: process.env.PLAYWRIGHT_HTML_OUTPUT_DIR ?? 'playwright-report', open: 'never' }],
    ],
    use: {
        ...devices['Desktop Chrome'],
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000',
        browserName: 'chromium',
        headless: process.env.PLAYWRIGHT_HEADED !== '1',
        screenshot: 'only-on-failure',
        trace: 'off',
        video: 'off',
        actionTimeout: 15_000,
    },
});
