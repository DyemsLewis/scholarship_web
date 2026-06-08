<script setup>
const currentYear = new Date().getFullYear();
</script>

<template>
    <footer class="mt-8 rounded-lg border border-slate-200 bg-white px-5 py-4 text-sm text-slate-500 shadow-sm">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <p>
                &copy; {{ currentYear }} Scholarship Admin Panel
            </p>
            <p class="font-semibold text-slate-600">
                Protected administrative workspace
            </p>
        </div>
    </footer>
</template>
