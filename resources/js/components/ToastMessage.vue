<script setup>
import { computed } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    type: {
        type: String,
        default: 'success',
        validator: (value) => ['success', 'error'].includes(value),
    },
    title: {
        type: String,
        required: true,
    },
    message: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(['close']);

const tone = computed(() => {
    if (props.type === 'error') {
        return {
            border: 'border-rose-200',
            icon: 'bg-rose-100 text-rose-700',
            title: 'text-rose-950',
            message: 'text-rose-700',
        };
    }

    return {
        border: 'border-amber-200',
        icon: 'bg-amber-100 text-amber-800',
        title: 'text-slate-950',
        message: 'text-slate-700',
    };
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-2 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="translate-y-0 opacity-100"
            leave-to-class="translate-y-2 opacity-0"
        >
            <div
                v-if="show"
                class="fixed right-4 top-4 z-50 w-[calc(100%-2rem)] max-w-sm"
                :role="type === 'error' ? 'alert' : 'status'"
            >
                <div
                    :class="[
                        'flex gap-3 rounded-lg border bg-white p-4 shadow-[0_18px_50px_rgba(15,23,42,0.18)]',
                        tone.border
                    ]"
                >
                    <div
                        :class="[
                            'flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-sm font-black',
                            tone.icon
                        ]"
                    >
                        {{ type === 'error' ? '!' : 'OK' }}
                    </div>

                    <div class="min-w-0 flex-1">
                        <p :class="['text-sm font-bold', tone.title]">
                            {{ title }}
                        </p>
                        <p :class="['mt-1 text-sm leading-5', tone.message]">
                            {{ message }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="h-7 rounded-md px-2 text-sm font-bold text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                        aria-label="Close message"
                        @click="emit('close')"
                    >
                        x
                    </button>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
