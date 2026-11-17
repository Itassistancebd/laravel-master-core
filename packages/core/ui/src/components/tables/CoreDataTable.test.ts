import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import CoreDataTable from './CoreDataTable.vue';

const columns = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'email', label: 'Email' },
];

describe('CoreDataTable', () => {
    it('shows serial numbers and sortable server-side headers', async () => {
        const wrapper = mount(CoreDataTable, {
            props: {
                columns,
                rows: [{ id: 21, name: 'Ada', email: 'ada@example.test' }],
                page: 2,
                lastPage: 4,
                total: 31,
                perPage: 10,
            },
        });

        expect(wrapper.text()).toContain('11');
        await wrapper.get('.mc-table__sort').trigger('click');
        expect(wrapper.emitted('update:sort')).toEqual([['name', 'asc']]);
    });

    it('limits pagination to five links and emits the selected page size', async () => {
        const wrapper = mount(CoreDataTable, {
            props: { columns, rows: [], page: 8, lastPage: 12, total: 120, perPage: 10 },
        });

        expect(wrapper.findAll('[aria-label^="Page "]')).toHaveLength(5);
        await wrapper.get('select').setValue('25');
        expect(wrapper.emitted('update:perPage')).toEqual([[25]]);
    });

    it('reports selection, column visibility, and empty state accessibly', async () => {
        const wrapper = mount(CoreDataTable, {
            props: {
                columns,
                rows: [{ id: 1, name: 'Ada', email: 'ada@example.test' }],
                page: 1,
                lastPage: 1,
                total: 1,
                perPage: 10,
            },
        });

        await wrapper.get('[aria-label="Select all rows on this page"]').setValue(true);
        expect(wrapper.emitted('update:selectedIds')?.at(-1)).toEqual([[1]]);

        const columnControl = wrapper.findAll('.mc-table__columns input')[0];
        await columnControl.setValue(false);
        expect(wrapper.emitted('update:visibleColumnKeys')?.at(-1)).toBeTruthy();

        await wrapper.setProps({ rows: [], emptyMessage: 'No users found.' });
        expect(wrapper.text()).toContain('No users found.');
    });

    it('keeps required columns visible and offers reset filters and boundary pagination', async () => {
        const wrapper = mount(CoreDataTable, {
            props: {
                columns: [{ key: 'name', label: 'Name', required: true }, ...columns.slice(1)],
                rows: [{ id: 1, name: 'Ada', email: 'ada@example.test' }],
                page: 2,
                lastPage: 3,
                total: 21,
                perPage: 10,
                activeFilterCount: 2,
            },
        });

        expect(wrapper.find('.mc-table__columns input').element).toHaveProperty('disabled', true);
        await wrapper.get('button[aria-label="First page"]').trigger('click');
        await wrapper.get('button[aria-label="Last page"]').trigger('click');
        await wrapper.get('.mc-table__tool-button').trigger('click');
        expect(wrapper.emitted('update:page')).toEqual([[1], [3]]);
        expect(wrapper.emitted('reset-filters')).toHaveLength(1);

        await wrapper.get('.mc-table__columns summary').trigger('click');
        await wrapper.get('.mc-table__columns .mc-table__tool-button').trigger('click');
        expect(wrapper.emitted('update:visibleColumnKeys')?.at(-1)).toEqual([['name', 'email']]);
    });

    it('restores allowed saved columns and persists later changes per table key', async () => {
        window.localStorage.setItem('mc-table-columns:test-users', JSON.stringify(['email', 'removed-column']));
        const wrapper = mount(CoreDataTable, {
            props: { columns: [{ key: 'name', label: 'Name', required: true }, columns[1]], rows: [], page: 1, lastPage: 1, total: 0, perPage: 10, preferenceKey: 'test-users' },
        });

        expect(wrapper.emitted('update:visibleColumnKeys')?.[0]).toEqual([['email', 'name']]);
        await wrapper.get('.mc-table__columns summary').trigger('click');
        await wrapper.findAll('.mc-table__columns input')[1].setValue(false);
        await wrapper.vm.$nextTick();
        expect(window.localStorage.getItem('mc-table-columns:test-users')).toBe('["name"]');
        window.localStorage.removeItem('mc-table-columns:test-users');
    });
});
