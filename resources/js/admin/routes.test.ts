import { describe, expect, it } from 'vitest';
import { createAdminRoutes } from './routes';

describe('Admin route registry', () => {
    it('does not register the removed Categories page', () => {
        const paths = createAdminRoutes(document.createElement('div')).map((route) => route.path);
        expect(paths).not.toContain('/app/categories');
    });
});
