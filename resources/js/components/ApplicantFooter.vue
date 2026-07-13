<script setup>
import { ref } from 'vue';
import TermsModal from './TermsModal.vue';

const currentYear = new Date().getFullYear();
const showTermsModal = ref(false);
</script>

<template>
    <footer class="mt-8 overflow-hidden rounded-lg border border-slate-800 bg-[#081426] text-slate-300">
        <div class="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="font-display text-base font-bold text-white">
                    Scholarship Portal
                </p>
                <p class="mt-1 text-xs text-slate-400">
                    Keep your profile and applications up to date.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs font-semibold">
                <a href="/dashboard/scholarships" class="transition hover:text-amber-200">Scholarships</a>
                <a href="/dashboard/applications" class="transition hover:text-amber-200">Applications</a>
                <button type="button" class="transition hover:text-amber-200" @click="showTermsModal = true">
                    Terms
                </button>
                <span class="text-slate-500">&copy; {{ currentYear }}</span>
            </div>
        </div>

        <TermsModal v-model="showTermsModal" context="general" />
    </footer>
</template>
