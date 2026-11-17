<script setup lang="ts">
import { RouterView } from 'vue-router';
import './auth.css';

const props = withDefaults(defineProps<{ resetToken?: string; resetEmail?: string }>(), {
    resetToken: '',
    resetEmail: '',
});
</script>

<template>
    <RouterView :reset-token="props.resetToken" :reset-email="props.resetEmail" />
</template>
