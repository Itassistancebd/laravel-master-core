<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { CoreButton } from '@itassistance-bd/ui';
import { getUnreadCount, listNotifications, markAllNotificationsRead, notificationsApiError, setNotificationRead } from '../api';
import type { CoreNotification } from '../types';

const notifications = ref<CoreNotification[]>([]);
const unreadCount = ref(0);
const status = ref<'all' | 'unread' | 'read'>('all');
const perPage = ref(25);
const currentPage = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);
const updatingId = ref<string | null>(null);
const markingAll = ref(false);
const error = ref('');
const notice = ref('');
const pageLinks = computed(() => {
    const start = Math.max(1, Math.min(currentPage.value - 2, lastPage.value - 4));
    const end = Math.min(lastPage.value, start + 4);
    return Array.from({ length: end - start + 1 }, (_, index) => start + index);
});

async function refreshCount(): Promise<void> {
    unreadCount.value = await getUnreadCount();
}

async function loadPage(page = 1): Promise<void> {
    loading.value = true;
    error.value = '';
    notice.value = '';
    try {
        const [result, count] = await Promise.all([
            listNotifications({ status: status.value, per_page: perPage.value, page }),
            getUnreadCount(),
        ]);
        notifications.value = result.items;
        currentPage.value = result.current_page;
        lastPage.value = result.last_page;
        total.value = result.total;
        unreadCount.value = count;
    } catch (exception) {
        // Keep server and transport details out of the notification page.
        error.value = notificationsApiError(exception).message;
        notifications.value = [];
    } finally {
        loading.value = false;
    }
}

async function setRead(notification: CoreNotification): Promise<void> {
    updatingId.value = notification.id;
    error.value = '';
    try {
        const updated = await setNotificationRead(notification.id, notification.read_at === null);
        notification.read_at = updated.read_at;
        await refreshCount();
        notice.value = updated.read_at === null ? 'Notification marked unread.' : 'Notification marked read.';
    } catch (exception) {
        error.value = notificationsApiError(exception).message;
    } finally {
        updatingId.value = null;
    }
}

async function markAllRead(): Promise<void> {
    markingAll.value = true;
    error.value = '';
    try {
        const result = await markAllNotificationsRead();
        await loadPage(currentPage.value);
        notice.value = result.remaining === 0
            ? 'All notifications marked read.'
            : `${result.updated} notifications marked read; ${result.remaining} remain. Select Mark all as read again to continue.`;
    } catch (exception) {
        error.value = notificationsApiError(exception).message;
    } finally {
        markingAll.value = false;
    }
}

function changeFilter(): void {
    void loadPage(1);
}

function changePageSize(event: Event): void {
    perPage.value = Number((event.target as HTMLSelectElement).value);
    void loadPage(1);
}

function formatDate(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.valueOf()) ? value : date.toLocaleString();
}

onMounted(() => { void loadPage(); });
</script>

<template>
    <main class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <header class="flex flex-col gap-4 border-b border-slate-200 pb-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-blue-700">Master Core</p>
                <h1 class="mt-1 text-2xl font-semibold text-slate-900">Notification Center</h1>
                <p class="mt-2 text-sm text-slate-600">Your account notices. Unread: <span class="font-semibold">{{ unreadCount }}</span></p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <RouterLink class="text-sm font-medium text-blue-700 underline-offset-4 hover:underline" to="/app">Account security</RouterLink>
                <CoreButton variant="secondary" :disabled="unreadCount === 0 || markingAll" :loading="markingAll" @click="markAllRead">Mark all as read</CoreButton>
            </div>
        </header>

        <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ error }} <RouterLink v-if="error.includes('session has expired')" class="font-semibold underline" :to="{ path: '/login', query: { redirect: '/app/notifications' } }">Sign in</RouterLink></p>
        <p v-if="notice" class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status">{{ notice }}</p>

        <section aria-label="Notification filters" class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <label class="flex flex-col gap-1 text-sm font-medium text-slate-700">Show
                <select v-model="status" class="mc-control min-w-40" @change="changeFilter">
                    <option value="all">All notifications</option>
                    <option value="unread">Unread</option>
                    <option value="read">Read</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm font-medium text-slate-700">Rows per page
                <select :value="perPage" class="mc-control min-w-32" @change="changePageSize">
                    <option :value="10">10</option><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option>
                </select>
            </label>
        </section>

        <div v-if="loading" class="rounded-lg border border-slate-200 bg-white p-8 text-center text-sm text-slate-600" role="status">Loading notifications…</div>
        <div v-else-if="notifications.length === 0" class="rounded-lg border border-dashed border-slate-300 bg-white p-10 text-center">
            <h2 class="text-lg font-medium text-slate-900">No notifications</h2>
            <p class="mt-2 text-sm text-slate-600">New account notices will appear here.</p>
        </div>
        <ul v-else class="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white">
            <li v-for="notification in notifications" :key="notification.id" class="flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-5" :class="notification.read_at === null ? 'bg-blue-50/50' : ''">
                <article class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-semibold text-slate-900">{{ notification.title }}</h2>
                        <span v-if="notification.read_at === null" class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">Unread</span>
                    </div>
                    <p class="mt-1 text-sm leading-6 text-slate-700">{{ notification.message }}</p>
                    <time class="mt-2 block text-xs text-slate-500" :datetime="notification.created_at">{{ formatDate(notification.created_at) }}</time>
                </article>
                <CoreButton variant="secondary" :loading="updatingId === notification.id" :disabled="updatingId !== null" @click="setRead(notification)">
                    {{ notification.read_at === null ? 'Mark read' : 'Mark unread' }}
                </CoreButton>
            </li>
        </ul>

        <footer v-if="!loading && total > 0" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-slate-600">Page {{ currentPage }} of {{ lastPage }} · {{ total }} total</p>
            <nav class="flex flex-wrap items-center gap-2" aria-label="Notification pages">
                <CoreButton variant="secondary" :disabled="currentPage <= 1" @click="loadPage(currentPage - 1)">Previous</CoreButton>
                <CoreButton v-for="page in pageLinks" :key="page" variant="secondary" :aria-current="currentPage === page ? 'page' : undefined" :disabled="currentPage === page" @click="loadPage(page)">{{ page }}</CoreButton>
                <CoreButton variant="secondary" :disabled="currentPage >= lastPage" @click="loadPage(currentPage + 1)">Next</CoreButton>
            </nav>
        </footer>
    </main>
</template>
