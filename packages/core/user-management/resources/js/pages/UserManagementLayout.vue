<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink, RouterView } from 'vue-router';
import { getCapabilities } from '../api';
import type { UserCapabilities } from '../types';

const capabilities = ref<UserCapabilities | null>(null);
onMounted(async () => {
    try {
        capabilities.value = await getCapabilities();
    } catch {
        capabilities.value = null;
    }
});

</script>

<template>
    <main class="mc-user-management">
        <nav class="mc-user-management__nav" aria-label="User management navigation">
            <RouterLink v-if="capabilities?.view" to="/app/users">Users</RouterLink>
            <RouterLink v-if="capabilities?.create" to="/app/users/create">Add user</RouterLink>
            <RouterLink v-if="capabilities?.view" to="/app/users/trash">Trash</RouterLink>
        </nav>
        <RouterView />
    </main>
</template>
