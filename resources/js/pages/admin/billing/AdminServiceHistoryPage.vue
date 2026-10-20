<script setup>
import { formatAdminServiceDateTime, useAdminServiceWorkspace } from '../../../composables/useAdminServiceWorkspace.js';
import AdminServiceWorkspaceShell from './components/AdminServiceWorkspaceShell.vue';
const { purchase, isLoading, errorMessage, successMessage, queueUrl } = useAdminServiceWorkspace('history');
function eventLabel(event) { return String(event.event_type || 'Service updated').replaceAll('_', ' '); }
function eventDetail(event) { return event.metadata?.cancellation_decision_note || event.metadata?.cancellation_reason || null; }
</script>

<template>
    <AdminServiceWorkspaceShell :purchase="purchase" active-section="closeout" :loading="isLoading" :error-message="errorMessage" :success-message="successMessage" :queue-url="queueUrl">
        <section class="admin-panel mt-4 overflow-hidden">
            <header class="flex items-center gap-3 border-b border-slate-200 px-6 py-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span><div><h2 class="text-lg font-black text-slate-950">Audit history</h2><p class="mt-0.5 text-sm text-slate-500">Immutable payment, delivery, and closeout events.</p></div></header>
            <ol v-if="purchase.lifecycle_events.length" class="divide-y divide-slate-200"><li v-for="event in [...purchase.lifecycle_events].reverse()" :key="event.id" class="grid grid-cols-[12rem_minmax(0,1fr)_10rem] gap-6 px-6 py-4"><time class="text-xs font-semibold text-slate-500">{{ formatAdminServiceDateTime(event.created_at) }}</time><div><p class="text-sm font-black capitalize text-slate-950">{{ eventLabel(event) }}</p><p v-if="event.delivery_from !== event.delivery_to" class="mt-1 text-xs text-slate-500">{{ event.delivery_from }} to {{ event.delivery_to }}</p><p v-if="eventDetail(event)" class="mt-2 text-sm text-slate-700">{{ eventDetail(event) }}</p></div><p class="text-right text-xs font-semibold text-slate-500">{{ event.actor_name }}</p></li></ol>
            <p v-else class="px-6 py-14 text-center text-sm text-slate-500">No lifecycle events are recorded.</p>
        </section>
    </AdminServiceWorkspaceShell>
</template>
