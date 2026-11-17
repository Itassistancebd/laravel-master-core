import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import CoreSearchSelect from './CoreSearchSelect.vue';

describe('CoreSearchSelect', () => {
    it('filters the option list and emits the selected value', async () => {
        const wrapper = mount(CoreSearchSelect, {
            props: {
                id: 'locale',
                modelValue: 'en',
                options: [
                    { value: 'en', label: 'English' },
                    { value: 'bn', label: 'Bangla' },
                ],
            },
        });

        const input = wrapper.get('[role="combobox"]');
        await input.trigger('focus');
        await input.setValue('Bang');

        expect(wrapper.findAll('[role="option"]')).toHaveLength(1);
        await wrapper.get('[role="option"]').trigger('click');
        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['bn']);
    });

    it('supports selecting the active option from the keyboard', async () => {
        const wrapper = mount(CoreSearchSelect, {
            props: {
                id: 'currency',
                modelValue: 'USD',
                options: [
                    { value: 'USD', label: 'US Dollar' },
                    { value: 'BDT', label: 'Bangladeshi Taka' },
                ],
            },
        });

        const input = wrapper.get('[role="combobox"]');
        await input.trigger('focus');
        await input.trigger('keydown', { key: 'ArrowDown' });
        await input.trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['BDT']);
    });
});
