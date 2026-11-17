<script setup lang="ts">
import { computed } from 'vue';
import { RouterView, useRoute } from 'vue-router';
import AdminShell from './AdminShell.vue';

const props = withDefaults(defineProps<{ resetToken?: string; resetEmail?: string }>(), { resetToken: '', resetEmail: '' });
const route = useRoute();
const isAdminPage = computed(() => route.path.startsWith('/app') || route.path === '/admin/settings');
</script>

<template>
    <AdminShell v-if="isAdminPage" />
    <RouterView v-else :reset-token="props.resetToken" :reset-email="props.resetEmail" />
</template>
