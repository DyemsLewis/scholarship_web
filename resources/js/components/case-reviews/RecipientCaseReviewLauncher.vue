<script setup>
import { onBeforeUnmount, ref } from 'vue';
import RecipientCaseReviewDialog from './RecipientCaseReviewDialog.vue';
import { concernError } from '../../support/agreementConcerns.js';
const props = defineProps({ readUrl: { type: String, required: true } });
const selected = ref(null), loading = ref(false), error = ref('');
let version = 0;
async function show() {
    if (loading.value) return; const token = ++version; loading.value = true; error.value = '';
    try { const { data } = await window.axios.get(props.readUrl, { portalToast: false }); if (token === version) selected.value = data.case; }
    catch (failure) { if (token === version) error.value = concernError(failure); }
    finally { if (token === version) loading.value = false; }
}
onBeforeUnmount(() => { version++; });
</script>
<template><div class="border-t border-slate-200 pt-4"><button type="button" :disabled="loading" class="inline-flex min-h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 disabled:opacity-40" @click="show"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>{{ loading ? 'Opening review...' : 'Case review and appeals' }}</button><p v-if="error" role="alert" class="mt-2 text-sm text-amber-900">{{ error }}</p><RecipientCaseReviewDialog :open="!!selected" :record="selected" @close="selected = null" /></div></template>
