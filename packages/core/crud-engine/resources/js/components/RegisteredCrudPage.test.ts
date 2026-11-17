import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import RegisteredCrudPage from './RegisteredCrudPage.vue';
import type { CrudPage, CrudPageDescriptor, CrudTransport } from '../types';

interface AccountRow { id: number; name: string; email: string; is_active: boolean }

const descriptor: CrudPageDescriptor<AccountRow> = {
    title: 'Fixture accounts', description: 'Test-only resource.',
    columns: [{ key: 'name', label: 'Name', sortable: true }, { key: 'email', label: 'Email' }],
    fields: [
        { key: 'name', label: 'Name', type: 'text', required: true },
        { key: 'email', label: 'Email', type: 'email', required: true },
    ],
    filters: [{ key: 'active', label: 'Status', options: [{ value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }] }],
};

const page = (items: AccountRow[] = []): CrudPage<AccountRow> => ({
    items, current_page: 1, per_page: 25, total: items.length, last_page: 1, from: items.length ? 1 : null, to: items.length || null,
});

describe('RegisteredCrudPage', () => {
    it('renders registered fields with shared UI and submits the owner transport', async () => {
        const transport: CrudTransport<AccountRow> = {
            list: vi.fn().mockResolvedValue(page()),
            create: vi.fn().mockResolvedValue({ id: 9, name: 'Ada', email: 'ada@example.test', is_active: true }),
            update: vi.fn(),
        };
        const wrapper = mount(RegisteredCrudPage<AccountRow>, {
            props: { descriptor, transport, capabilities: { create: true, edit: false, delete: false } },
        });
        await flushPromises();
        expect(wrapper.text()).toContain('Fixture accounts');
        await wrapper.get('button').trigger('click');
        const forms = wrapper.findAll('form');
        await forms[1].find('input[type="text"]').setValue('Ada');
        await forms[1].find('input[type="email"]').setValue('ada@example.test');
        await forms[1].trigger('submit');
        await flushPromises();
        expect(transport.create).toHaveBeenCalledWith({ name: 'Ada', email: 'ada@example.test' });
    });

    it('hides mutation controls and selection when capabilities are absent', async () => {
        const transport: CrudTransport<AccountRow> = { list: vi.fn().mockResolvedValue(page([{ id: 1, name: 'Ada', email: 'ada@example.test', is_active: true }])), create: vi.fn(), update: vi.fn() };
        const wrapper = mount(RegisteredCrudPage<AccountRow>, {
            props: { descriptor, transport, capabilities: {} },
        });
        await flushPromises();
        expect(wrapper.text()).not.toContain('Create');
        expect(wrapper.text()).not.toContain('Edit');
        expect(wrapper.text()).not.toContain('Delete');
        expect(wrapper.find('input[aria-label="Select all rows on this page"]').exists()).toBe(false);
    });

    it('confirms destructive actions before calling the owner transport', async () => {
        const transport: CrudTransport<AccountRow> = {
            list: vi.fn().mockResolvedValue(page([{ id: 1, name: 'Ada', email: 'ada@example.test', is_active: true }])),
            create: vi.fn(), update: vi.fn(), remove: vi.fn().mockResolvedValue(undefined),
        };
        const wrapper = mount(RegisteredCrudPage<AccountRow>, {
            props: { descriptor, transport, capabilities: { delete: true } },
            attachTo: document.body,
        });
        await flushPromises();
        const remove = wrapper.findAll('button').find((button) => button.text().trim() === 'Delete');
        await remove?.trigger('click');
        expect(document.body.textContent).toContain('Delete this record?');
        expect(transport.remove).not.toHaveBeenCalled();
        const confirm = Array.from(document.body.querySelectorAll('button')).filter((button) => button.textContent?.trim() === 'Delete').at(-1);
        confirm?.click();
        await flushPromises();
        expect(transport.remove).toHaveBeenCalledWith(1);
        wrapper.unmount();
    });

    it('loads and renders details through the owner transport', async () => {
        const record = { id: 1, name: 'Ada', email: 'ada@example.test', is_active: true };
        const transport: CrudTransport<AccountRow> = { list: vi.fn().mockResolvedValue(page([record])), get: vi.fn().mockResolvedValue(record), create: vi.fn(), update: vi.fn() };
        const wrapper = mount(RegisteredCrudPage<AccountRow>, { props: { descriptor, transport, capabilities: { view: true } } });
        await flushPromises();
        await wrapper.findAll('button').find((button) => button.text().trim() === 'View')?.trigger('click');
        await flushPromises();
        expect(transport.get).toHaveBeenCalledWith(1);
        expect(wrapper.text()).toContain('Ada');
        expect(wrapper.text()).toContain('Fixture accounts details');
    });
});
