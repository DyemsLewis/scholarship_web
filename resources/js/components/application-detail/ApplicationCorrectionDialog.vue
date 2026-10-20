<script setup>
const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    correction: {
        type: Object,
        default: () => ({}),
    },
    response: {
        type: String,
        default: '',
    },
    processing: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close', 'submit', 'update:response']);
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-[3000] flex items-center justify-center bg-slate-950/70 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="correction-dialog-title"
            @click.self="!processing && emit('close')"
        >
            <form class="flex max-h-[calc(100vh-2rem)] w-full max-w-xl flex-col overflow-hidden rounded-md bg-white shadow-2xl" @submit.prevent="emit('submit')">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Requested update</p>
                        <h2 id="correction-dialog-title" class="mt-1 text-xl font-bold text-slate-950">Tell the provider what changed</h2>
                    </div>
                    <button type="button" :disabled="processing" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-300 text-slate-600 hover:bg-slate-50 disabled:opacity-50" aria-label="Close correction dialog" @click="emit('close')">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5">
                    <div class="border-l-2 border-amber-400 bg-amber-50 px-3 py-3 text-sm leading-6 text-amber-950">
                        {{ correction.message }}
                    </div>

                    <div v-if="correction.targets?.length">
                        <p class="text-xs font-bold text-slate-500">Requested areas</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span v-for="target in correction.targets" :key="target.key" class="rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-700">
                                {{ target.label }}
                            </span>
                        </div>
                    </div>

                    <div>
                        <label for="application-correction-response" class="text-sm font-bold text-slate-900">What did you update?</label>
                        <textarea
                            id="application-correction-response"
                            :value="response"
                            rows="5"
                            maxlength="1500"
                            class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-slate-600 focus:ring-2 focus:ring-slate-200"
                            placeholder="Example: I replaced the unreadable report card with a clear copy."
                            @input="emit('update:response', $event.target.value)"
                        ></textarea>
                    </div>
                </div>

                <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end">
                    <button type="button" :disabled="processing" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-100 disabled:opacity-50" @click="emit('close')">
                        Cancel
                    </button>
                    <button type="submit" :disabled="processing" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50">
                        {{ processing ? 'Sending...' : 'Send update' }}
                    </button>
                </footer>
            </form>
        </div>
    </Teleport>
</template>
