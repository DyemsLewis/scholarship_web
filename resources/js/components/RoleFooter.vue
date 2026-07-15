<script setup>
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
</script>

<template>
    <footer class="mt-8 rounded-lg border border-slate-200 bg-white px-5 py-4 text-sm text-slate-500 shadow-sm">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <p>&copy; {{ currentYear }} {{ panelName }}</p>
            <p class="font-semibold text-slate-600">{{ message }}</p>
        </div>
    </footer>
</template>
