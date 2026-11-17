<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { CoreButton, CoreField, CoreFormSection, CoreTextInput, CoreToast } from '@itassistance-bd/ui';
import { createUser, getUser, updateUser, userApiErrorMessage, userApiValidationErrors } from '../api';

const route = useRoute();
const router = useRouter();
const id = route.params.user as string | undefined;
const isEdit = Boolean(id);
const form = reactive({ name: '', email: '' });
const errors = ref<Record<string, string[]>>({});
const pageError = ref('');
const toast = ref('');
const loading = ref(false);

onMounted(async () => {
    if (!id) return;
    loading.value = true;
    try {
        const user = await getUser(id);
        form.name = user.name;
        form.email = user.email;
    } catch (error) {
        pageError.value = userApiErrorMessage(error);
    } finally {
        loading.value = false;
    }
});

async function submit(): Promise<void> {
    loading.value = true;
    errors.value = {};
    pageError.value = '';
    try {
        const user = isEdit ? await updateUser(id as string, form) : await createUser(form);
        toast.value = isEdit ? 'User details updated.' : 'User created. A password setup link was requested.';
        await router.replace(`/app/users/${user.id}`);
    } catch (error) {
        errors.value = userApiValidationErrors(error);
        pageError.value = userApiErrorMessage(error);
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <section class="w-full min-w-0 rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        <div class="mb-6 flex items-start justify-between gap-4">
            <div><h2 class="text-xl font-semibold text-slate-900">{{ isEdit ? 'Edit user' : 'Create user' }}</h2><p class="mt-1 text-sm text-slate-600">{{ isEdit ? 'Update the user name and email address.' : 'Create an account and send a secure password setup link.' }}</p></div>
            <RouterLink class="text-sm text-blue-700 hover:underline" to="/app/users">Back to users</RouterLink>
        </div>
        <form class="grid gap-5" @submit.prevent="submit">
            <CoreFormSection number="1" title="Account details" description="Enter the user's name and email address.">
                <CoreField v-slot="{ id: controlId, describedBy, errorId }" label="Name" required :error="errors.name?.[0]">
                    <CoreTextInput :id="controlId" v-model="form.name" autocomplete="name" required :invalid="Boolean(errorId)" :aria-describedby="describedBy" />
                </CoreField>
                <CoreField v-slot="{ id: controlId, describedBy, errorId }" label="Email address" required :error="errors.email?.[0]">
                    <CoreTextInput :id="controlId" v-model="form.email" type="email" autocomplete="email" required :invalid="Boolean(errorId)" :aria-describedby="describedBy" />
                </CoreField>
            </CoreFormSection>
            <p v-if="pageError" class="rounded bg-red-50 p-3 text-sm text-red-800" role="alert">{{ pageError }}</p>
            <div class="flex gap-3"><CoreButton type="submit" variant="primary" :loading="loading">{{ isEdit ? 'Save changes' : 'Create user' }}</CoreButton><RouterLink class="rounded border border-slate-300 px-4 py-2 text-sm" to="/app/users">Cancel</RouterLink></div>
        </form>
        <CoreToast v-if="toast" :message="toast" @dismiss="toast = ''" />
    </section>
</template>
