import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import CoreMultiSelect from './CoreMultiSelect.vue';

describe('CoreMultiSelect', () => {
    it('renders selected values and emits a changed selection', async () => {
        const wrapper = mount(CoreMultiSelect, {
            props: {
                id: 'locales',
                modelValue: ['en'],
                options: [
                    { value: 'en', label: 'English' },
                    { value: 'bn', label: 'Bangla' },
                ],
            },
        });

        expect(wrapper.text()).toContain('English');
        await wrapper.get('[role="combobox"]').trigger('focus');
        await wrapper.findAll('[role="option"]')[1].trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['en', 'bn']]);
    });
});
