<script setup>
import { formatServiceDateTime, useProviderServiceWorkspace } from '../../../composables/useProviderServiceWorkspace.js';
import BillingServiceWorkspaceShell from './components/BillingServiceWorkspaceShell.vue';
const { purchase, workspace, isLoading, errorMessage, successMessage, directoryUrl } = useProviderServiceWorkspace('history');
function eventLabel(event) { return String(event.event_type || 'Service updated').replaceAll('_', ' '); }
function eventDetail(event) { return event.metadata?.cancellation_decision_note || event.metadata?.cancellation_reason || null; }
</script>

<template>
    <BillingServiceWorkspaceShell :purchase="purchase" :workspace="workspace" active-section="closeout" :loading="isLoading" :error-message="errorMessage" :success-message="successMessage" :directory-url="directoryUrl">
        <section class="mt-4 border border-slate-200 bg-white shadow-sm">
            <header class="border-b border-slate-200 px-6 py-4"><h2 class="text-base font-bold text-slate-950">Service history</h2><p class="mt-1 text-xs text-slate-500">A read-only record of payment and delivery changes.</p></header>
            <ol v-if="purchase.lifecycle_events.length" class="divide-y divide-slate-200">
                <li v-for="event in [...purchase.lifecycle_events].reverse()" :key="event.id" class="grid grid-cols-[11rem_minmax(0,1fr)] gap-6 px-6 py-4"><time class="text-xs font-semibold text-slate-500">{{ formatServiceDateTime(event.created_at) }}</time><div><p class="text-sm font-bold capitalize text-slate-950">{{ eventLabel(event) }}</p><p class="mt-1 text-xs text-slate-500">{{ event.actor_name }}<span v-if="event.delivery_from !== event.delivery_to"> · {{ event.delivery_from }} to {{ event.delivery_to }}</span></p><p v-if="eventDetail(event)" class="mt-2 text-sm text-slate-700">{{ eventDetail(event) }}</p></div></li>
            </ol>
            <p v-else class="px-6 py-12 text-center text-sm text-slate-500">No lifecycle events are recorded.</p>
        </section>
    </BillingServiceWorkspaceShell>
</template>
