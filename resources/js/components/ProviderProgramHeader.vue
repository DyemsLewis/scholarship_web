<script setup>
import TaskPageHeader from './TaskPageHeader.vue';
import { labelFromKey } from '../support/display';

const props = defineProps({
    programId: {
        type: [Number, String],
        required: true,
    },
    title: {
        type: String,
        default: 'Scholarship program',
    },
    status: {
        type: String,
        default: '',
    },
    section: {
        type: String,
        required: true,
    },
});

const statusLabel = () => labelFromKey(props.status || 'draft');
</script>

<template>
    <div class="mb-3">
        <a href="/provider/programs" class="inline-flex items-center gap-2 text-xs font-bold text-slate-500 transition hover:text-slate-950">
            <i class="fa-solid fa-arrow-left text-[10px] text-amber-700" aria-hidden="true"></i>
            Back to programs
        </a>
    </div>

    <TaskPageHeader
        theme="provider"
        :eyebrow="section"
        :title="title"
        icon="fa-solid fa-graduation-cap"
    >
        <template v-if="status || $slots.meta" #meta>
            <span v-if="status">Status: <strong class="text-slate-700">{{ statusLabel() }}</strong></span>
            <slot name="meta"></slot>
        </template>
        <template v-if="$slots.actions" #actions>
            <div class="grid w-full grid-cols-1 gap-2 sm:flex sm:w-auto sm:flex-wrap sm:items-center">
                <slot name="actions"></slot>
            </div>
        </template>
    </TaskPageHeader>
</template>
