import { mount } from '@vue/test-utils';
import { h } from 'vue';
import { describe, expect, it } from 'vitest';
import CoreField from './CoreField.vue';

describe('CoreField', () => {
    it('associates its label, help text, and validation error with the control', () => {
        const wrapper = mount(CoreField, {
            props: {
                id: 'application-name',
                label: 'Application name',
                help: 'Shown to visitors.',
                error: 'This field is required.',
                required: true,
            },
            slots: {
                default: ({ id, describedBy }: { id: string; describedBy?: string }) =>
                    h('input', { id, 'aria-describedby': describedBy }),
            },
        });

        expect(wrapper.get('label').attributes('for')).toBe('application-name');
        expect(wrapper.get('input').attributes('aria-describedby')).toContain('application-name-help');
        expect(wrapper.get('input').attributes('aria-describedby')).toContain('application-name-error');
        expect(wrapper.get('[role="alert"]').text()).toBe('This field is required.');
    });
});
