<script setup>
import { confirmChange } from "../support/outcomeConfirmation.js";
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import FeedbackAlertModal from './FeedbackAlertModal.vue';
import { APPLICATION_MESSAGES_READ_EVENT } from '../support/applicationMessageEvents.js';
import { providerApplicationMessagesLinks } from '../support/providerApplicationMessagesLink.js';
import { startVisiblePolling } from '../support/visiblePolling.js';
const providerAlerts = window.portalUser?.role === 'provider';
const chatEnabled = window.portalUser?.role === 'applicant' || providerApplicationMessagesLinks(window.portalUser).length > 0;

const NOTIFICATION_SYNC_EVENT = 'portal-notifications:sync';

const props = defineProps({
    align: {
        type: String,
        default: 'right',
    },
    label: {
        type: String,
        default: 'Notifications',
    },
    mode: {
        type: String,
        default: 'compact',
    },
    centered: {
        type: Boolean,
        default: false,
    },
});

const isOpen = ref(false);
const isLoading = ref(false);
const isMarkingAllRead = ref(false);
const errorMessage = ref('');
const loadFailed = ref(false);
const notifications = ref([]);
const unreadCount = ref(0);
const chatAlerts = ref([]);
const unreadChats = ref(0);
const totalUnread = computed(() => unreadCount.value + unreadChats.value);
const chatLifetime = new AbortController();
let loadingChats = false;
let reloadChats = false;
let stopChatPolling;
const root = ref(null);
const selectedNotification = ref(null);

const isSidebar = computed(() => ['sidebar', 'sidebar-compact'].includes(props.mode));
const isCompactSidebar = computed(() => props.mode === 'sidebar-compact');
const isCentered = computed(() => props.centered);
const panelAlignment = computed(() => (props.align === 'left' ? 'left-0' : 'right-0'));
const buttonClasses = computed(() => {
    if (isCompactSidebar.value) {
        return 'relative flex w-full items-center justify-between rounded-md px-3 py-2 text-sm font-semibold text-slate-400 transition hover:bg-white/5 hover:text-white';
    }

    if (isSidebar.value) {
        return 'relative flex w-full items-center justify-between rounded-md border border-white/10 px-4 py-2.5 text-sm font-bold text-slate-300 transition hover:border-amber-300/40 hover:bg-white/5 hover:text-white';
    }

    return 'relative flex h-10 w-10 items-center justify-center rounded-md border border-white/20 text-sm font-semibold text-slate-100 transition hover:bg-white hover:text-slate-950';
});

function normalizeNotification(notification) {
    return {
        ...notification,
        is_read: Boolean(notification.is_read),
    };
}

function applyNotificationList(items, count) {
    notifications.value = (items ?? []).map(normalizeNotification);
    unreadCount.value = Number.isFinite(Number(count))
        ? Number(count)
        : notifications.value.filter((item) => !item.is_read).length;
}

async function loadNotifications({ silent = false, interactive = false } = {}) {
    if (!silent) {
        isLoading.value = true;
    }

    errorMessage.value = '';

    try {
        const response = await window.axios.get('/notifications');
        applyNotificationList(response.data.notifications ?? [], response.data.unread_count);
        loadFailed.value = false;
    } catch (error) {
        loadFailed.value = true;
        if (!providerAlerts || interactive) errorMessage.value = 'Unable to load notifications right now.';
    } finally {
        if (!silent) {
            isLoading.value = false;
        }
    }
}

async function loadChatAlerts() {
    if (!chatEnabled || loadingChats || chatLifetime.signal.aborted) return;
    loadingChats = true;
    try {
        const { data } = await window.axios.get('/notifications/application-messages', {
            headers: { Accept: 'application/json' },
            signal: chatLifetime.signal,
            portalToast: false,
            timeout: 10000,
        });
        if (chatLifetime.signal.aborted) return;
        chatAlerts.value = Array.isArray(data?.conversations) ? data.conversations : [];
        unreadChats.value = Number(data?.unread_count) || 0;
    } catch (error) {
        if ([401, 403, 404].includes(error.response?.status)) {
            chatAlerts.value = [];
            unreadChats.value = 0;
        }
    } finally {
        loadingChats = false;
        if (reloadChats) {
            reloadChats = false;
            void loadChatAlerts();
        }
    }
}

function refreshAfterRead() {
    if (document.visibilityState !== 'visible') return;
    if (loadingChats) reloadChats = true;
    else void loadChatAlerts();
}

function refreshNotifications() {
    void loadChatAlerts();
    void loadNotifications({ interactive: true });
}

function toggleDropdown() {
    isOpen.value = !isOpen.value;

    if (isOpen.value) {
        loadNotifications({ silent: notifications.value.length > 0, interactive: true });
        void loadChatAlerts();
    }
}

function closeDropdown() {
    isOpen.value = false;
}

function closeNotificationModal() {
    selectedNotification.value = null;
}

function closeOnOutsideClick(event) {
    if (!isOpen.value || !root.value || root.value.contains(event.target)) {
        return;
    }

    closeDropdown();
}

function closeOnEscape(event) {
    if (event.key === 'Escape') {
        if (selectedNotification.value) {
            closeNotificationModal();
            return;
        }

        closeDropdown();
    }
}

function syncFromEvent(event) {
    const detail = event.detail ?? {};

    if (Number.isFinite(Number(detail.unread_count))) {
        unreadCount.value = Number(detail.unread_count);
    }

    if (detail.all_read) {
        notifications.value = notifications.value.map((notification) => ({
            ...notification,
            is_read: true,
            read_at: notification.read_at || new Date().toISOString(),
        }));
        return;
    }

    if (!detail.notification_id) {
        return;
    }

    notifications.value = notifications.value.map((notification) => (
        notification.id === detail.notification_id
            ? {
                ...notification,
                is_read: true,
                read_at: detail.read_at || notification.read_at || new Date().toISOString(),
            }
            : notification
    ));
}

function dispatchNotificationSync(detail) {
    window.dispatchEvent(new CustomEvent(NOTIFICATION_SYNC_EVENT, { detail }));
}

function formatDate(value) {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}

async function openNotification(notification) {
    if (!notification.is_read) {
        try {
            const response = await window.axios.patch(`/notifications/${notification.id}/read`);
            const readAt = response.data.notification?.read_at ?? new Date().toISOString();

            notification.is_read = true;
            notification.read_at = readAt;
            unreadCount.value = Number.isFinite(Number(response.data.unread_count))
                ? Number(response.data.unread_count)
                : Math.max(0, unreadCount.value - 1);

            dispatchNotificationSync({
                notification_id: notification.id,
                read_at: readAt,
                unread_count: unreadCount.value,
            });
        } catch (error) {
            errorMessage.value = 'Unable to mark that notification as read.';
            return;
        }
    }

    closeDropdown();
    selectedNotification.value = { ...notification };
}

function proceedToNotification() {
    const actionUrl = selectedNotification.value?.action_url;

    if (!actionUrl) {
        closeNotificationModal();
        return;
    }

    window.location.href = actionUrl;
}

async function markAllRead() {
    if (unreadCount.value <= 0) {
        return;
    }

    const canMark = () => unreadCount.value > 0 && !isMarkingAllRead.value;
    if (!await confirmChange({
        title: chatEnabled ? 'Mark all portal updates as read?' : 'Mark all notifications as read?',
        message: chatEnabled ? 'Unread chats stay in the bell until you read them in their conversations.'
            : 'All unread notifications will be marked read. Their messages will remain available.',
    }, canMark) || !canMark()) return;
    isMarkingAllRead.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.patch('/notifications/read-all');
        applyNotificationList(response.data.notifications ?? notifications.value.map((notification) => ({
            ...notification,
            is_read: true,
            read_at: notification.read_at || new Date().toISOString(),
        })), response.data.unread_count ?? 0);
        dispatchNotificationSync({
            all_read: true,
            unread_count: 0,
        });
    } catch (error) {
        errorMessage.value = 'Unable to mark notifications as read.';
    } finally {
        isMarkingAllRead.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', closeOnOutsideClick);
    document.addEventListener('keydown', closeOnEscape);
    window.addEventListener(NOTIFICATION_SYNC_EVENT, syncFromEvent);
    loadNotifications();
    if (chatEnabled) {
        window.addEventListener(APPLICATION_MESSAGES_READ_EVENT, refreshAfterRead);
        if (document.visibilityState === 'visible' && navigator.onLine !== false) void loadChatAlerts();
        stopChatPolling = startVisiblePolling(loadChatAlerts, { interval: 30000 });
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('click', closeOnOutsideClick);
    document.removeEventListener('keydown', closeOnEscape);
    window.removeEventListener(NOTIFICATION_SYNC_EVENT, syncFromEvent);
    window.removeEventListener(APPLICATION_MESSAGES_READ_EVENT, refreshAfterRead);
    stopChatPolling?.();
    chatLifetime.abort();
});
</script>

<template>
    <div ref="root" :class="['relative', isSidebar ? 'w-full' : '']">
        <FeedbackAlertModal v-if="providerAlerts" v-model="errorMessage" title="Notification action unavailable" retry-label="Refresh notifications" :busy="isLoading || isMarkingAllRead" @retry="loadNotifications({ interactive: true })" />
        <button
            type="button"
            :class="buttonClasses"
            :aria-expanded="isOpen.toString()"
            :aria-label="label"
            aria-haspopup="true"
            :title="label"
            @click="toggleDropdown"
        >
            <span :class="['flex items-center', isCompactSidebar ? 'gap-3' : 'gap-2']">
                <span v-if="isCompactSidebar" class="grid h-6 w-6 shrink-0 place-items-center text-xs text-slate-500">
                    <i class="fa-solid fa-bell" aria-hidden="true"></i>
                </span>
                <i v-else class="fa-solid fa-bell text-xs" aria-hidden="true"></i>
                <span v-if="isSidebar">
                    {{ label }}
                </span>
            </span>
            <span
                v-if="totalUnread > 0"
                :class="[
                    'flex items-center justify-center rounded-full bg-amber-300 font-black text-slate-950',
                    isSidebar ? 'min-w-5 px-1.5 py-0.5 text-[10px]' : 'absolute -right-1 -top-1 h-5 min-w-5 px-1 text-[10px]',
                ]"
            >
                {{ totalUnread > 9 ? '9+' : totalUnread }}
            </span>
            <span v-else-if="isSidebar" class="text-xs font-bold text-slate-500">
                0
            </span>
        </button>

        <Teleport to="body" :disabled="!isCentered">
            <div
                v-if="isOpen"
                :class="[
                    isCentered
                        ? 'fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm'
                        : 'absolute z-50 mt-2',
                    !isCentered ? panelAlignment : '',
                ]"
                @click.self="closeDropdown"
            >
                <section
                    :class="[
                        'overflow-hidden rounded-lg border border-slate-200 bg-white text-slate-900 shadow-2xl',
                        isCentered
                            ? 'w-full max-w-xl'
                            : 'w-[min(22rem,calc(100vw-2rem))]',
                    ]"
                    :role="isCentered ? 'dialog' : undefined"
                    :aria-modal="isCentered ? 'true' : undefined"
                    :aria-label="isCentered ? 'Notifications' : undefined"
                    @click.stop
                >
                    <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                        <div>
                            <p class="text-sm font-black text-slate-950">
                                Notifications
                            </p>
                            <p class="text-xs text-slate-500">
                                Recent portal updates
                            </p>
                        </div>
                        <div class="flex items-center gap-1">
                            <button
                                v-if="unreadCount > 0"
                                type="button"
                                :disabled="isMarkingAllRead"
                                class="rounded-md px-2.5 py-1.5 text-xs font-bold text-slate-600 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                @click.stop="markAllRead"
                            >
                                {{ isMarkingAllRead ? 'Marking...' : chatEnabled ? 'Mark updates read' : 'Mark all' }}
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2.5 py-1.5 text-xs font-bold text-amber-700 transition hover:bg-amber-50"
                                @click.stop="refreshNotifications"
                            >
                                Refresh
                            </button>
                            <button
                                v-if="isCentered"
                                type="button"
                                class="ml-1 flex h-8 w-8 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-slate-900"
                                aria-label="Close notifications"
                                title="Close"
                                @click.stop="closeDropdown"
                            >
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <div class="max-h-[min(55dvh,24rem)] overflow-y-auto">
                        <section v-if="chatAlerts.length" class="border-b border-slate-200" aria-label="Unread application messages">
                            <h3 class="flex items-center gap-2 bg-slate-50 px-4 py-2 text-xs font-bold text-slate-700">
                                <i class="fa-regular fa-comments" aria-hidden="true"></i>Unread chats ({{ unreadChats }})
                            </h3>
                            <a
                                v-for="chat in chatAlerts"
                                :key="chat.key"
                                :href="chat.href"
                                class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-left transition hover:bg-amber-50"
                            >
                                <span class="min-w-0 break-words">
                                    <span class="block text-sm font-bold text-slate-950">{{ chat.applicant_name || chat.program_title }}</span>
                                    <span class="block text-xs leading-5 text-slate-600">{{ chat.applicant_name ? chat.program_title + ' / ' : '' }}{{ chat.unread_count }} unread {{ chat.unread_count === 1 ? 'message' : 'messages' }}</span>
                                </span>
                                <i class="fa-solid fa-arrow-right shrink-0 text-xs text-slate-500" aria-hidden="true"></i>
                            </a>
                            <a v-if="unreadChats > chatAlerts.length" :href="providerAlerts ? '/provider/workspaces/messages?filter=unread' : '/dashboard/applications'" class="block px-4 py-2 text-xs font-bold text-amber-800 underline">
                                {{ providerAlerts ? 'See all unread in Messages' : 'Go to your applications' }}
                            </a>
                        </section>

                        <div v-if="isLoading" class="px-4 py-5 text-sm text-slate-500">
                            Loading notifications...
                        </div>

                        <FeedbackToast v-else-if="errorMessage &amp;&amp; !providerAlerts" :message="errorMessage" fallback />

                        <div v-else-if="providerAlerts && loadFailed && notifications.length === 0" class="px-4 py-5"><button type="button" class="text-sm font-bold text-slate-700 underline" @click="loadNotifications({ interactive: true })">Retry loading notifications</button></div>
                        <div v-else-if="notifications.length === 0 && chatAlerts.length === 0" class="px-4 py-5 text-sm text-slate-500">
                            You are all caught up.
                        </div>

                        <div v-else-if="notifications.length">
                            <h3 v-if="chatAlerts.length" class="bg-slate-50 px-4 py-2 text-xs font-bold text-slate-700">Portal updates</h3>
                            <button
                                v-for="notification in notifications"
                                :key="notification.id"
                                type="button"
                                class="block w-full border-t border-slate-100 px-4 py-3 text-left transition hover:bg-slate-50"
                                @click="openNotification(notification)"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-sm font-black leading-5 text-slate-950">
                                        {{ notification.title }}
                                    </p>
                                    <span
                                        v-if="!notification.is_read"
                                        class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.12em] text-amber-800"
                                    >
                                        New
                                    </span>
                                </div>
                                <p class="mt-1 text-xs leading-5 text-slate-600">
                                    {{ notification.message }}
                                </p>
                                <p v-if="notification.created_at" class="mt-2 text-[11px] font-bold uppercase tracking-[0.12em] text-slate-400">
                                    {{ formatDate(notification.created_at) }}
                                </p>
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="selectedNotification"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/65 p-4 backdrop-blur-sm"
                role="dialog"
                aria-modal="true"
                aria-labelledby="notification-detail-title"
                @click.self="closeNotificationModal"
            >
                <section class="w-full max-w-lg overflow-hidden rounded-lg border border-slate-200 bg-white text-slate-900 shadow-2xl">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                <i class="fa-solid fa-bell" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Notification</p>
                                <h2 id="notification-detail-title" class="mt-1 text-lg font-bold leading-6 text-slate-950">
                                    {{ selectedNotification.title }}
                                </h2>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-300 bg-white text-slate-600 transition hover:bg-slate-50 hover:text-slate-950"
                            aria-label="Close notification"
                            @click="closeNotificationModal"
                        >
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>

                    <div class="px-5 py-5">
                        <p class="text-sm leading-6 text-slate-700">
                            {{ selectedNotification.message }}
                        </p>
                        <p v-if="selectedNotification.created_at" class="mt-4 text-xs font-bold uppercase tracking-[0.12em] text-slate-400">
                            {{ formatDate(selectedNotification.created_at) }}
                        </p>
                    </div>

                    <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            @click="closeNotificationModal"
                        >
                            Close
                        </button>
                        <button
                            v-if="selectedNotification.action_url"
                            type="button"
                            class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                            @click="proceedToNotification"
                        >
                            Proceed
                            <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                        </button>
                    </footer>
                </section>
            </div>
        </Teleport>
    </div>
</template>
