<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import { authenticatedUser } from '@itassistance-bd/auth';
import { getCurrentPermissions } from '@itassistance-bd/roles-permissions';
import AdminIcon from './AdminIcon.vue';

interface NavItem { label: string; to: string; ability: string | null; icon: string; group: 'Workspace' | 'System' | 'Account' }
interface CoreNotification { id: string; title: string; message: string; created_at: string; read_at: string | null }
const route = useRoute();
const router = useRouter();
const mountPoint = document.querySelector<HTMLElement>('#master-core-auth, #master-core-settings');
const notificationsApiBase = mountPoint?.dataset.notificationsApiBase ?? '/api/v1/notifications';
const masterCoreName = mountPoint?.dataset.masterCoreName ?? 'Master Core';
const masterCoreVersion = mountPoint?.dataset.masterCoreVersion ?? '';
const access = ref<Record<string, boolean>>({});
const unread = ref<number | null>(null);
const notifications = ref<CoreNotification[]>([]);
const notificationError = ref('');
const mobileOpen = ref(false);
const collapsed = ref(false);
const profileOpen = ref(false);
const appMenuOpen = ref(false);
const notificationOpen = ref(false);
const userGroupOpen = ref(false);
const pageSearchOpen = ref(false);
const pageSearchQuery = ref('');
const pageSearchInput = ref<HTMLInputElement | null>(null);
const pageSearchTrigger = ref<HTMLButtonElement | null>(null);
const logoutError = ref('');
const signingOut = ref(false);
const navigationExpanded = computed(() => window.matchMedia('(max-width: 760px)').matches ? mobileOpen.value : !collapsed.value);

const items: NavItem[] = [
    { label: 'Dashboard', to: '/app', ability: null, icon: 'grid', group: 'Workspace' },
    { label: 'Users', to: '/app/users', ability: 'users', icon: 'users', group: 'Workspace' },
    { label: 'Roles', to: '/app/roles', ability: 'roles', icon: 'shield', group: 'Workspace' },
    { label: 'Permissions', to: '/app/permissions', ability: 'permissions', icon: 'key', group: 'Workspace' },
    { label: 'Module Manager', to: '/app/modules', ability: 'modules', icon: 'layers', group: 'System' },
    { label: 'System Health', to: '/app/system-health', ability: 'health', icon: 'heart', group: 'System' },
    { label: 'Audit Logs', to: '/app/audit-logs', ability: 'audit', icon: 'clock', group: 'System' },
    { label: 'Notifications', to: '/app/notifications', ability: null, icon: 'bell', group: 'System' },
    { label: 'Account Security', to: '/app/account-security', ability: null, icon: 'key', group: 'Account' },
    { label: 'Settings', to: '/admin/settings', ability: 'settings', icon: 'settings', group: 'Account' },
];
const visibleItems = computed(() => items.filter((item) => item.ability === null || access.value[item.ability]));
const navigationGroups = computed(() => (['Workspace', 'System', 'Account'] as const)
    .map((label) => ({ label, items: visibleItems.value.filter((item) => item.group === label) }))
    .filter((group) => group.items.length > 0));
const workspaceItems = computed(() => navigationGroups.value.find((group) => group.label === 'Workspace')?.items ?? []);
const standaloneWorkspaceItems = computed(() => workspaceItems.value.filter((item) => item.to === '/app'));
const userManagementItems = computed(() => workspaceItems.value.filter((item) => item.to !== '/app'));
const userManagementActive = computed(() => userManagementItems.value.some((item) => route.path.startsWith(item.to)));
const pageSearchResults = computed(() => {
    const query = pageSearchQuery.value.trim().toLocaleLowerCase();
    return visibleItems.value.filter((item) => !query || item.label.toLocaleLowerCase().includes(query));
});
const title = computed(() => {
    if (route.path === '/app') return 'Dashboard';
    if (route.path.startsWith('/app/account-security')) return 'Account security';
    return visibleItems.value.find((item) => item.to !== '/app' && route.path.startsWith(item.to))?.label ?? 'Master Core';
});
function toggleSidebar(): void {
    if (window.matchMedia('(max-width: 760px)').matches) mobileOpen.value = !mobileOpen.value;
    else collapsed.value = !collapsed.value;
}
async function openPageSearch(): Promise<void> { pageSearchOpen.value = true; await nextTick(); pageSearchInput.value?.focus(); }
async function closePageSearch(): Promise<void> { pageSearchOpen.value = false; pageSearchQuery.value = ''; await nextTick(); pageSearchTrigger.value?.focus(); }
function handlePageSearchShortcut(event: KeyboardEvent): void {
    if ((event.ctrlKey || event.metaKey) && event.key.toLocaleLowerCase() === 'k') { event.preventDefault(); void openPageSearch(); }
    else if (event.key === 'Escape') { pageSearchOpen.value = false; appMenuOpen.value = false; notificationOpen.value = false; profileOpen.value = false; }
}
function closePopups(): void { appMenuOpen.value = false; notificationOpen.value = false; profileOpen.value = false; }
function formatNotificationDate(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}
async function loadNotifications(): Promise<void> {
    try {
        const [countResponse, listResponse] = await Promise.all([
            axios.get(`${notificationsApiBase}/unread-count`),
            axios.get(notificationsApiBase, { params: { status: 'all', page: 1, per_page: 10 } }),
        ]);
        unread.value = Number.isInteger(countResponse.data?.data?.unread_count) ? countResponse.data.data.unread_count : null;
        notifications.value = Array.isArray(listResponse.data?.data) ? listResponse.data.data.slice(0, 5) : [];
        notificationError.value = '';
    } catch { unread.value = null; notificationError.value = 'Notifications could not be loaded.'; }
}
onMounted(async () => {
    window.addEventListener('keydown', handlePageSearchShortcut);
    try {
        const permissions = new Set(await getCurrentPermissions());
        access.value = {
            users: permissions.has('view-users') || permissions.has('manage-users'),
            roles: permissions.has('view-roles'),
            permissions: permissions.has('view-permissions'),
            audit: permissions.has('view-audit-logs'),
            modules: permissions.has('view-modules'),
            health: permissions.has('view-system-health'),
            settings: permissions.has('view-settings'),
        };
    } catch { /* Protected pages remain usable if the dashboard summary endpoint is unavailable. */ }
    await loadNotifications();
});
onBeforeUnmount(() => window.removeEventListener('keydown', handlePageSearchShortcut));
async function logout(): Promise<void> {
    signingOut.value = true; logoutError.value = '';
    try { await axios.post('/logout'); authenticatedUser.value = null; await router.replace('/login'); }
    catch { logoutError.value = 'Sign out could not be completed. Please try again.'; }
    finally { signingOut.value = false; }
}
</script>

<template>
    <div class="mc-admin" :class="{ 'mc-admin--collapsed': collapsed }">
        <button v-if="mobileOpen" class="mc-admin__scrim" aria-label="Close navigation" @click="mobileOpen = false" />
        <aside class="mc-admin__sidebar" :class="{ 'mc-admin__sidebar--open': mobileOpen }">
            <RouterLink to="/app" class="mc-admin__brand" @click="mobileOpen = false">
                <span class="mc-admin__brand-mark">M</span><span class="mc-admin__brand-copy"><strong>{{ masterCoreName }}</strong><small>Administration</small></span><span v-if="masterCoreVersion" class="mc-admin__version">v{{ masterCoreVersion }}</span>
            </RouterLink>
            <nav aria-label="Main navigation" class="mc-admin__nav">
                <section v-if="standaloneWorkspaceItems.length" class="mc-admin__nav-group" aria-label="Workspace">
                    <p class="mc-admin__nav-group-title">Workspace</p>
                    <RouterLink v-for="item in standaloneWorkspaceItems" :key="item.to" :to="item.to" class="mc-admin__nav-link" :class="{ 'is-active': route.path === item.to }" :title="item.label" @click="mobileOpen = false">
                        <span class="mc-admin__nav-icon"><AdminIcon :name="item.icon" /></span><span class="mc-admin__nav-label">{{ item.label }}</span>
                    </RouterLink>
                    <div v-if="userManagementItems.length" class="mc-admin__nav-subgroup">
                        <button type="button" class="mc-admin__nav-link mc-admin__nav-parent" :class="{ 'is-active': userManagementActive }" :aria-expanded="userGroupOpen || userManagementActive" @click="userGroupOpen = !userGroupOpen">
                            <span class="mc-admin__nav-icon"><AdminIcon name="users" /></span><span class="mc-admin__nav-label">User Management</span><AdminIcon name="chevron" class="mc-admin__nav-chevron" />
                        </button>
                        <div v-if="userGroupOpen || userManagementActive" class="mc-admin__nav-children">
                            <RouterLink v-for="item in userManagementItems" :key="item.to" :to="item.to" class="mc-admin__nav-child" :class="{ 'is-active': route.path.startsWith(item.to) }" @click="mobileOpen = false">
                                <span class="mc-admin__nav-child-dot" /><span>{{ item.label }}</span>
                            </RouterLink>
                        </div>
                    </div>
                </section>
                <section v-for="group in navigationGroups.filter((entry) => entry.label !== 'Workspace')" :key="group.label" class="mc-admin__nav-group" :aria-label="group.label">
                    <p class="mc-admin__nav-group-title">{{ group.label }}</p>
                    <RouterLink v-for="item in group.items" :key="item.to" :to="item.to" class="mc-admin__nav-link" :class="{ 'is-active': route.path.startsWith(item.to) }" :title="item.label" @click="mobileOpen = false">
                        <span class="mc-admin__nav-icon"><AdminIcon :name="item.icon" /></span><span class="mc-admin__nav-label">{{ item.label }}</span><span v-if="item.icon === 'bell' && unread" class="mc-admin__count">{{ unread }}</span>
                    </RouterLink>
                </section>
            </nav>
            <div class="mc-admin__sidebar-footer"><span class="mc-admin__footer-mark">MC</span><span class="mc-admin__footer-copy"><strong>{{ masterCoreName }}</strong><small v-if="masterCoreVersion">Core version {{ masterCoreVersion }}</small></span></div>
        </aside>
        <div class="mc-admin__main">
            <header class="mc-admin__topbar">
                <button class="mc-admin__menu-button" :aria-label="navigationExpanded ? 'Collapse navigation' : 'Expand navigation'" :aria-expanded="navigationExpanded" @click="toggleSidebar"><AdminIcon name="menu" /></button>
                <div class="mc-admin__crumb"><span>Workspace</span><span>/</span><strong>{{ title }}</strong></div>
                <div class="mc-admin__top-actions">
                    <div class="mc-admin__search-wrap">
                        <button ref="pageSearchTrigger" type="button" class="mc-admin__search-button" aria-label="Search pages" :aria-expanded="pageSearchOpen" aria-haspopup="dialog" @click="pageSearchOpen ? closePageSearch() : openPageSearch()"><AdminIcon name="search" /><span>Search pages</span><kbd>Ctrl K</kbd></button>
                        <section v-if="pageSearchOpen" class="mc-admin__search-panel" role="dialog" aria-modal="false" aria-label="Search admin pages">
                            <label class="mc-admin__search-field"><AdminIcon name="search" /><input ref="pageSearchInput" v-model="pageSearchQuery" type="search" placeholder="Search pages" autocomplete="off"></label>
                            <nav class="mc-admin__search-results" aria-label="Page search results"><RouterLink v-for="item in pageSearchResults" :key="item.to" :to="item.to" @click="closePageSearch"><span class="mc-admin__search-result-icon"><AdminIcon :name="item.icon" /></span><span>{{ item.label }}</span><AdminIcon name="arrow" /></RouterLink><p v-if="pageSearchResults.length === 0" role="status">No matching pages.</p></nav>
                        </section>
                    </div>
                    <div class="mc-admin__popup-wrap">
                        <button class="mc-admin__top-icon" type="button" aria-label="Quick navigation" :aria-expanded="appMenuOpen" aria-haspopup="menu" @click="appMenuOpen = !appMenuOpen; notificationOpen = false; profileOpen = false"><AdminIcon name="apps" /></button>
                        <nav v-if="appMenuOpen" class="mc-admin__app-menu" role="menu" aria-label="Quick navigation">
                            <p>Quick navigation</p><RouterLink v-for="item in visibleItems" :key="item.to" :to="item.to" role="menuitem" @click="closePopups"><span><AdminIcon :name="item.icon" /></span>{{ item.label }}</RouterLink>
                        </nav>
                    </div>
                    <div class="mc-admin__popup-wrap">
                        <button class="mc-admin__top-icon mc-admin__notification-trigger" type="button" aria-label="Notifications" :aria-expanded="notificationOpen" aria-haspopup="dialog" @click="notificationOpen = !notificationOpen; appMenuOpen = false; profileOpen = false"><AdminIcon name="bell" /><span v-if="unread" class="mc-admin__notification-dot" /></button>
                        <section v-if="notificationOpen" class="mc-admin__notification-menu" role="dialog" aria-label="Recent notifications">
                            <header><div><strong>Notifications</strong><span v-if="unread">{{ unread }} unread</span></div><RouterLink to="/app/notifications" @click="closePopups">View all</RouterLink></header>
                            <p v-if="notificationError" class="mc-admin__notification-empty" role="status">{{ notificationError }}</p>
                            <ul v-else-if="notifications.length"><li v-for="notification in notifications" :key="notification.id" :class="{ 'is-unread': !notification.read_at }"><span class="mc-admin__notification-mark"><AdminIcon name="bell" /></span><div><strong>{{ notification.title }}</strong><p>{{ notification.message }}</p><time>{{ formatNotificationDate(notification.created_at) }}</time></div></li></ul>
                            <p v-else class="mc-admin__notification-empty">You’re all caught up.</p>
                        </section>
                    </div>
                    <div class="mc-admin__profile-wrap">
                        <button class="mc-admin__profile" aria-haspopup="menu" :aria-expanded="profileOpen" @click="profileOpen = !profileOpen; appMenuOpen = false; notificationOpen = false"><span class="mc-admin__avatar">{{ (authenticatedUser?.name ?? authenticatedUser?.email ?? 'A').slice(0, 1).toUpperCase() }}</span><span class="mc-admin__profile-name">{{ authenticatedUser?.name ?? authenticatedUser?.email }}</span><span class="mc-admin__profile-chevron" aria-hidden="true" /></button>
                        <div v-if="profileOpen" class="mc-admin__profile-menu" role="menu"><div class="mc-admin__profile-summary"><strong>{{ authenticatedUser?.name }}</strong><small>{{ authenticatedUser?.email }}</small></div><RouterLink to="/app/account-security" role="menuitem" @click="profileOpen = false">Account security</RouterLink><button role="menuitem" :disabled="signingOut" @click="logout">Sign out</button></div>
                    </div>
                </div>
            </header>
            <p v-if="logoutError" class="mc-admin__logout-error" role="alert">{{ logoutError }}</p>
            <main class="mc-admin__content"><RouterView /></main>
        </div>
    </div>
</template>
