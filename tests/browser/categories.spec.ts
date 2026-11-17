import { expect, test, login } from './support';

test('Categories is absent from navigation and application routes', async ({ page }) => {
    await login(page, 'admin');
    await expect(page.getByRole('link', { name: 'Categories' })).toHaveCount(0);

    await page.goto('/app/categories');
    await expect(page.getByRole('heading', { name: 'Categories' })).toHaveCount(0);

    const apiStatus = await page.evaluate(async () => (
        await fetch('/api/v1/core/categories', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
    ).status);
    expect(apiStatus).toBe(404);
});
