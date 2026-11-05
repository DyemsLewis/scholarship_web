<script setup>
import { ref } from 'vue';
import FilePreviewModal from '../FilePreviewModal.vue';
defineProps({ files: { type: Array, default: () => [] }, removable: Boolean, busy: Boolean });
defineEmits(['remove']);
const preview = ref(null);
</script>

<template>
    <ul v-if="files.length" class="divide-y divide-slate-200 border-y border-slate-200">
        <li v-for="file in files" :key="file.id" class="flex items-center gap-3 py-3">
            <i class="fa-regular fa-file-lines w-5 shrink-0 text-center text-slate-500" aria-hidden="true"></i>
            <button type="button" class="min-h-9 min-w-0 flex-1 break-words text-left text-sm font-semibold text-slate-800 underline decoration-slate-300 underline-offset-4" @click="preview = { ...file, original_name: file.name }">{{ file.name }}</button>
            <button v-if="removable && file.remove_url" type="button" :disabled="busy" :aria-label="'Remove ' + file.name" class="grid h-9 w-9 shrink-0 place-items-center border border-slate-300 text-slate-600 disabled:opacity-40" @click="$emit('remove', file)"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </li>
    </ul>
    <FilePreviewModal :file="preview" title="Supporting evidence" @close="preview = null" />
</template>
