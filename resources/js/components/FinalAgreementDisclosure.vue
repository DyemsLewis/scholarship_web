<script setup>
import ApplicantTextSummary from './ApplicantTextSummary.vue';

defineProps({ policy: { type: Object, default: null } });
</script>

<template>
    <aside v-if="policy?.final_agreement_required" data-final-agreement-disclosure class="border-l-4 border-amber-400 bg-amber-50/70 px-4 py-4 sm:px-5">
        <div class="flex items-start gap-3">
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-white text-amber-800"><i class="fa-solid fa-file-signature text-sm leading-none" aria-hidden="true"></i></span>
            <div class="min-w-0 flex-1">
                <h3 class="text-sm font-bold text-slate-950">Final provider agreement required</h3>
                <p class="mt-1 text-sm leading-6 text-slate-700">After confirming your intention to proceed, complete the provider's final agreement. Confirming intention is not signing a contract.</p>
                <div v-if="policy.instructions" class="mt-3 border-t border-amber-200 pt-3">
                    <p class="mb-1 text-xs font-bold text-slate-800">Provider's disclosed instructions</p>
                    <ApplicantTextSummary :text="policy.instructions" label="final-agreement instructions" />
                </div>
                <p class="mt-2 text-xs leading-5 text-slate-600">The provider handles and keeps the actual contract outside this platform.</p>
            </div>
        </div>
    </aside>
</template>
