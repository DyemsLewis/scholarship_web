<script setup>
defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    event: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close']);
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open && event"
            class="fixed inset-0 z-[3000] flex items-center justify-center bg-slate-950/70 p-3 sm:p-5"
            role="presentation"
            @click.self="emit('close')"
        >
            <section class="flex max-h-[calc(100vh-1.5rem)] w-full max-w-xl flex-col overflow-hidden rounded-md bg-white shadow-2xl sm:max-h-[calc(100vh-2.5rem)]" role="dialog" aria-modal="true" aria-labelledby="history-event-title">
                <header class="flex items-start gap-3 border-t-4 border-amber-400 px-4 py-4 sm:px-5">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300">
                        <i :class="event.icon" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">{{ event.category_label }}</p>
                        <h2 id="history-event-title" class="mt-1 text-lg font-bold text-slate-950">{{ event.title }}</h2>
                    </div>
                    <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-300 text-slate-500 hover:bg-slate-50 hover:text-slate-950" aria-label="Close event details" @click="emit('close')">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto border-t border-slate-200 px-4 py-4 sm:px-5">
                    <p class="text-sm leading-6 text-slate-700">{{ event.description }}</p>

                    <dl class="mt-4 divide-y divide-slate-200 border-y border-slate-200">
                        <div class="grid gap-1 py-3 sm:grid-cols-[9rem_minmax(0,1fr)]">
                            <dt class="text-xs font-semibold text-slate-500">Recorded</dt>
                            <dd class="text-sm font-bold text-slate-950">{{ event.occurred_label }}</dd>
                        </div>
                        <div class="grid gap-1 py-3 sm:grid-cols-[9rem_minmax(0,1fr)]">
                            <dt class="text-xs font-semibold text-slate-500">Recorded by</dt>
                            <dd class="text-sm font-bold text-slate-950">{{ event.actor_label }}</dd>
                        </div>
                        <div v-for="detail in event.details" :key="detail.label" class="grid gap-1 py-3 sm:grid-cols-[9rem_minmax(0,1fr)]">
                            <dt class="text-xs font-semibold text-slate-500">{{ detail.label }}</dt>
                            <dd class="text-sm font-bold text-slate-950">{{ detail.value }}</dd>
                        </div>
                    </dl>

                    <p class="mt-4 flex items-start gap-2 text-xs leading-5 text-slate-500">
                        <i class="fa-solid fa-lock mt-0.5 text-slate-400" aria-hidden="true"></i>
                        This is a read-only application milestone. Internal provider notes are not included.
                    </p>
                </div>

                <footer class="flex justify-end border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-5">
                    <button type="button" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="emit('close')">
                        Close
                    </button>
                </footer>
            </section>
        </div>
    </Teleport>
</template>
