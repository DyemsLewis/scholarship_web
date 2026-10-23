<script setup>
defineOptions({ name: 'ApplicantPreviewViewport' });
</script>

<template>
    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-200" data-applicant-preview-viewport>
        <div class="min-h-full w-full bg-slate-100">
            <slot />
        </div>
    </div>
</template>
