<script setup>
import AdminLoadState from './AdminLoadState.vue';

defineProps({
    title: {
        type: String,
        default: '',
    },
    description: {
        type: String,
        default: '',
    },
    icon: {
        type: String,
        default: 'fa-solid fa-table-list',
    },
    compact: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    error: { type: String, default: '' },
    loadingMessage: { type: String, default: 'Loading records...' },
});
defineEmits(['retry']);
</script>

<template>
    <section class="admin-panel admin-table-frame w-full min-w-0 overflow-hidden" :aria-busy="loading">
        <header v-if="title || description || $slots.actions" class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-4">
            <div class="flex min-w-0 items-center gap-3">
                <span v-if="icon" class="admin-table-icon grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600" aria-hidden="true">
                    <i :class="[icon, 'text-sm']"></i>
                </span>
                <div class="min-w-0">
                    <h2 v-if="title" class="text-base font-black text-slate-950">{{ title }}</h2>
                    <p v-if="description" class="mt-1 text-sm text-slate-500">{{ description }}</p>
                </div>
            </div>
            <div v-if="$slots.actions" class="shrink-0">
                <slot name="actions"></slot>
            </div>
        </header>

        <div v-if="$slots.filters" class="border-b border-slate-200 bg-slate-50 p-3">
            <slot name="filters"></slot>
        </div>

        <div class="staff-table-viewport w-full min-w-0" :class="{ 'staff-table-viewport--compact': compact }">
            <AdminLoadState :loading="loading" :error="error" :loading-message="loadingMessage" @retry="$emit('retry')" />
            <slot v-if="!loading && !error"></slot>
        </div>

        <footer v-if="$slots.footer && !error" class="border-t border-slate-200 bg-slate-50 px-5 py-3">
            <slot name="footer"></slot>
        </footer>
    </section>
</template>
