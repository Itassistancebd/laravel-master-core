import axios from 'axios';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SettingsApp from './SettingsApp.vue';
import type { SettingsGroup } from './types';

const generalGroup: SettingsGroup = {
    key: 'general',
    label: 'General',
    settings: {
        application_name: {
            key: 'application_name',
            label: 'Application name',
            type: 'text',
            value: 'Portal',
            default: 'Laravel',
            description: 'Shown to visitors.',
            required: true,
        },
    },
};

describe('SettingsApp', () => {
    afterEach(() => vi.restoreAllMocks());

    it('loads a group, saves edited values, and reports success', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({
            data: { success: true, data: { general: generalGroup } },
        } as Awaited<ReturnType<typeof axios.get>>);
        vi.spyOn(axios, 'put').mockResolvedValue({
            data: { success: true, data: { ...generalGroup, settings: {
                application_name: { ...generalGroup.settings.application_name, value: 'New Portal' },
            } } },
        } as Awaited<ReturnType<typeof axios.put>>);

        const apiBase = '/custom/v2/settings';
        const wrapper = mount(SettingsApp, { props: { apiBase } });
        await flushPromises();

        expect(wrapper.text()).toContain('Application Settings');
        expect(wrapper.get('input').element.value).toBe('Portal');

        await wrapper.get('input').setValue('New Portal');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith(apiBase);
        expect(axios.put).toHaveBeenCalledWith(`${apiBase}/general`, {
            settings: { application_name: 'New Portal' },
        });
        expect(wrapper.text()).toContain('General settings saved.');
    });

    it('shows an accessible load failure message when the API is unavailable', async () => {
        vi.spyOn(axios, 'get').mockRejectedValue(new Error('network unavailable'));

        const wrapper = mount(SettingsApp);
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('Settings could not be loaded.');
    });

    it('shows field validation errors returned by the API', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({
            data: { success: true, data: { general: generalGroup } },
        } as Awaited<ReturnType<typeof axios.get>>);
        vi.spyOn(axios, 'put').mockRejectedValue({
            response: { data: { errors: { 'settings.application_name': ['The name is too long.'] } } },
        });
        vi.spyOn(axios, 'isAxiosError').mockReturnValue(true);

        const wrapper = mount(SettingsApp);
        await flushPromises();
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('.mc-field__error').text()).toBe('The name is too long.');
        expect(wrapper.get('.mc-toast').text()).toContain('Review the highlighted fields.');
    });

    it('confirms a group reset and reports completion', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({
            data: { success: true, data: { general: generalGroup } },
        } as Awaited<ReturnType<typeof axios.get>>);
        vi.spyOn(axios, 'post').mockResolvedValue({
            data: { success: true, data: { ...generalGroup, settings: {
                application_name: { ...generalGroup.settings.application_name, value: 'Laravel' },
            } } },
        } as Awaited<ReturnType<typeof axios.post>>);

        const wrapper = mount(SettingsApp);
        await flushPromises();
        await wrapper.get('button.mc-button--secondary').trigger('click');
        await flushPromises();

        const confirmButton = document.body.querySelectorAll('[role="dialog"] button')[1] as HTMLButtonElement;
        confirmButton.click();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/api/v1/settings/general/reset');
        expect(wrapper.text()).toContain('General settings reset to their defaults.');
    });
});
