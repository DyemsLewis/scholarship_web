<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

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
});

const isOpen = ref(false);
const isLoading = ref(false);
const isMarkingAllRead = ref(false);
const errorMessage = ref('');
const notifications = ref([]);
const unreadCount = ref(0);
const root = ref(null);

const isSidebar = computed(() => props.mode === 'sidebar');
const panelAlignment = computed(() => (props.align === 'left' ? 'left-0' : 'right-0'));
const buttonClasses = computed(() => {
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

async function loadNotifications({ silent = false } = {}) {
    if (!silent) {
        isLoading.value = true;
    }

    errorMessage.value = '';

    try {
        const response = await window.axios.get('/notifications');
        applyNotificationList(response.data.notifications ?? [], response.data.unread_count);
    } catch (error) {
        errorMessage.value = 'Unable to load notifications right now.';
    } finally {
        if (!silent) {
            isLoading.value = false;
        }
    }
}

function toggleDropdown() {
    isOpen.value = !isOpen.value;

    if (isOpen.value) {
        loadNotifications({ silent: notifications.value.length > 0 });
    }
}

function closeDropdown() {
    isOpen.value = false;
}

function closeOnOutsideClick(event) {
    if (!isOpen.value || !root.value || root.value.contains(event.target)) {
        return;
    }

    closeDropdown();
}

function closeOnEscape(event) {
    if (event.key === 'Escape') {
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

    if (notification.action_url) {
        window.location.href = notification.action_url;
        return;
    }

    closeDropdown();
}

async function markAllRead() {
    if (unreadCount.value <= 0) {
        return;
    }

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
});

onBeforeUnmount(() => {
    document.removeEventListener('click', closeOnOutsideClick);
    document.removeEventListener('keydown', closeOnEscape);
    window.removeEventListener(NOTIFICATION_SYNC_EVENT, syncFromEvent);
});
</script>

<template>
    <div ref="root" :class="['relative', isSidebar ? 'w-full' : '']">
        <button
            type="button"
            :class="buttonClasses"
            :aria-expanded="isOpen.toString()"
            :aria-label="label"
            aria-haspopup="true"
            :title="label"
            @click="toggleDropdown"
        >
            <span class="flex items-center gap-2">
                <i class="fa-solid fa-bell text-xs" aria-hidden="true"></i>
                <span v-if="isSidebar">
                    {{ label }}
                </span>
            </span>
            <span
                v-if="unreadCount > 0"
                :class="[
                    'flex items-center justify-center rounded-full bg-amber-300 font-black text-slate-950',
                    isSidebar ? 'min-w-6 px-2 py-0.5 text-[11px]' : 'absolute -right-1 -top-1 h-5 min-w-5 px-1 text-[10px]',
                ]"
            >
                {{ unreadCount > 9 ? '9+' : unreadCount }}
            </span>
            <span v-else-if="isSidebar" class="text-xs font-bold text-slate-500">
                0
            </span>
        </button>

        <div
            v-if="isOpen"
            :class="[
                'absolute z-50 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-lg border border-slate-200 bg-white text-slate-900 shadow-2xl',
                panelAlignment,
            ]"
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
                        {{ isMarkingAllRead ? 'Marking...' : 'Mark all' }}
                    </button>
                    <button
                        type="button"
                        class="rounded-md px-2.5 py-1.5 text-xs font-bold text-amber-700 transition hover:bg-amber-50"
                        @click.stop="loadNotifications"
                    >
                        Refresh
                    </button>
                </div>
            </div>

            <div v-if="isLoading" class="px-4 py-5 text-sm text-slate-500">
                Loading notifications...
            </div>

            <div v-else-if="errorMessage" class="px-4 py-5 text-sm text-rose-700">
                {{ errorMessage }}
            </div>

            <div v-else-if="notifications.length === 0" class="px-4 py-5 text-sm text-slate-500">
                You are all caught up.
            </div>

            <div v-else class="max-h-80 overflow-y-auto">
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
    </div>
</template>
