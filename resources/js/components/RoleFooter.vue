<script setup>
import { ref } from 'vue';
import TermsModal from './TermsModal.vue';

defineProps({
    panelName: {
        type: String,
        required: true,
    },
    message: {
        type: String,
        required: true,
    },
});

const currentYear = new Date().getFullYear();
const showNoticeModal = ref(false);
const noticeContext = ref('general');

function showNotice(context) {
    noticeContext.value = context;
    showNoticeModal.value = true;
}
</script>

<template>
    <footer class="mt-8 rounded-lg border border-slate-200 bg-white px-5 py-4 text-sm text-slate-500 shadow-sm">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <p>&copy; {{ currentYear }} {{ panelName }}</p>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                <p class="font-semibold text-slate-600">{{ message }}</p>
                <button type="button" class="font-semibold text-slate-700 hover:text-slate-950" @click="showNotice('general')">Terms</button>
                <button type="button" class="font-semibold text-slate-700 hover:text-slate-950" @click="showNotice('privacy')">Privacy Notice</button>
            </div>
        </div>

        <TermsModal v-model="showNoticeModal" :context="noticeContext" />
    </footer>
</template>
