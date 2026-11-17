import { defineComponent } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { useCrudResource } from './useCrudResource';
import type { CrudPage, CrudTransport } from './types';

interface AccountRow { id: number; name: string; email: string }

const page = (items: AccountRow[] = []): CrudPage<AccountRow> => ({
    items, current_page: 1, per_page: 25, total: items.length, last_page: 1, from: items.length ? 1 : null, to: items.length || null,
});

describe('useCrudResource', () => {
    it('loads server query state and resets paging for search, filters, and sort changes', async () => {
        const transport: CrudTransport<AccountRow> = {
            list: vi.fn().mockResolvedValue(page([{ id: 1, name: 'Ada', email: 'ada@example.test' }])),
            create: vi.fn(), update: vi.fn(),
        };
        let state!: ReturnType<typeof useCrudResource<AccountRow>>;
        mount(defineComponent({ setup() { state = useCrudResource(transport, 'name'); return () => null; } }));

        await state.load();
        await state.setPage(3);
        await state.search('Ada');
        await state.setFilter('active', '1');
        await state.setSort('email', 'desc');

        expect(transport.list).toHaveBeenLastCalledWith(expect.objectContaining({
            search: 'Ada', filters: { active: '1' }, sort_by: 'email', sort_direction: 'desc', page: 1,
        }));
        expect(state.rows.value).toHaveLength(1);
    });

    it('keeps backend validation errors and does not report a failed save as successful', async () => {
        const transport: CrudTransport<AccountRow> = {
            list: vi.fn().mockResolvedValue(page()),
            create: vi.fn().mockRejectedValue({ response: { status: 422, data: { message: 'Invalid input.', errors: { email: ['Invalid email.'] } } } }),
            update: vi.fn(),
        };
        let state!: ReturnType<typeof useCrudResource<AccountRow>>;
        mount(defineComponent({ setup() { state = useCrudResource(transport, 'name'); return () => null; } }));

        expect(await state.save({ name: 'Ada' })).toBeNull();
        expect(state.fieldErrors.value.email).toEqual(['Invalid email.']);
        expect(state.notice.value).toBe('Invalid input.');
        expect(state.noticeVariant.value).toBe('error');
        await flushPromises();
    });

    it('uses atomic bulk transport and reports authorization errors safely', async () => {
        const transport: CrudTransport<AccountRow> = {
            list: vi.fn().mockResolvedValue(page()), create: vi.fn(), update: vi.fn(),
            bulk: vi.fn().mockRejectedValue({ response: { status: 403, data: {} } }),
        };
        let state!: ReturnType<typeof useCrudResource<AccountRow>>;
        mount(defineComponent({ setup() { state = useCrudResource(transport, 'name'); return () => null; } }));
        state.selectedIds.value = [1, 2];

        await state.removeSelected();

        expect(transport.bulk).toHaveBeenCalledWith('delete', [1, 2]);
        expect(state.noticeVariant.value).toBe('error');
        expect(state.notice.value).toBe('You are not authorized to perform this action.');
    });
});
