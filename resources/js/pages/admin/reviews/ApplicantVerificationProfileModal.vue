<script setup>
import { computed, ref, useId, watch } from 'vue';
import { useModalAccessibility } from '../../../composables/useModalAccessibility';

const props = defineProps({
    open: Boolean,
    applicant: { type: Object, required: true },
    sections: { type: Array, default: () => [] },
    initialSection: { type: String, default: 'personal' },
    busy: Boolean,
});
const emit = defineEmits(['close']);
const id = useId();
const dialog = ref(null);
const content = ref(null);
const navigation = ref(null);
const active = ref('personal');
const tabs = computed(() => [...props.sections.map(section => ({
    key: section.id, label: { personal: 'Personal', academic: 'Learning record', household: 'Household', location: 'Home address' }[section.id] || section.title, icon: section.icon,
})), { key: 'photo', label: 'Photo review', icon: 'fa-solid fa-image-portrait' }]);
const current = computed(() => props.sections.find(section => section.id === active.value));
const close = () => { if (!props.busy) emit('close'); };
const { handleKeydown } = useModalAccessibility(() => props.open, dialog, close);
watch(() => props.open, open => {
    if (open) active.value = tabs.value.some(tab => tab.key === props.initialSection) ? props.initialSection : tabs.value[0].key;
});
watch(active, () => content.value?.scrollTo({ top: 0 }), { flush: 'post' });
function changeTab(event, index) {
    const last = tabs.value.length - 1;
    const keys = { ArrowRight: index === last ? 0 : index + 1, ArrowLeft: index === 0 ? last : index - 1, Home: 0, End: last };
    if (!(event.key in keys) || props.busy) return;
    event.preventDefault();
    active.value = tabs.value[keys[event.key]].key;
    navigation.value?.querySelectorAll('[role="tab"]')[keys[event.key]]?.focus();
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[2000] grid place-items-center bg-slate-950/60 p-6" @click.self="close">
            <section ref="dialog" data-applicant-verification-profile class="verification-profile-dialog flex w-full max-w-6xl flex-col overflow-hidden rounded-sm border border-slate-300 bg-white text-slate-900 shadow-2xl" role="dialog" aria-modal="true" :aria-labelledby="`${id}-title`" :aria-busy="busy" tabindex="-1" @keydown="handleKeydown">
                <header class="flex shrink-0 items-start justify-between gap-6 border-b border-slate-200 px-7 py-5"><div class="min-w-0"><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Applicant profile</p><h2 :id="`${id}-title`" class="mt-1 break-words font-display text-2xl font-bold text-slate-950">{{ applicant.name || 'Applicant record' }}</h2><p class="mt-1 break-words text-sm text-slate-500">{{ applicant.email }}</p></div><button type="button" :disabled="busy" class="grid h-10 w-10 shrink-0 place-items-center rounded-sm border border-slate-300 text-slate-600 hover:bg-slate-50 disabled:opacity-40" aria-label="Close applicant profile" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
                <div ref="navigation" role="tablist" aria-label="Applicant profile sections" class="flex shrink-0 border-b border-slate-200 bg-slate-50 px-7">
                    <button v-for="(tab, index) in tabs" :id="`${id}-tab-${tab.key}`" :key="tab.key" type="button" role="tab" :disabled="busy" :aria-selected="active === tab.key" :aria-controls="`${id}-panel`" :tabindex="active === tab.key ? 0 : -1" :class="['flex min-h-12 flex-1 items-center justify-center gap-2 border-b-2 px-3 text-sm font-bold disabled:opacity-40', active === tab.key ? 'border-amber-400 bg-white text-slate-950' : 'border-transparent text-slate-500 hover:text-slate-950']" @click="active = tab.key" @keydown="changeTab($event, index)"><i :class="[tab.icon, 'text-xs']" aria-hidden="true"></i>{{ tab.label }}</button>
                </div>
                <div :id="`${id}-panel`" ref="content" role="tabpanel" :aria-labelledby="`${id}-tab-${active}`" tabindex="0" class="min-h-0 flex-1 overflow-y-auto overscroll-contain [overflow-wrap:anywhere]">
                    <div v-if="active === 'photo'" class="p-7"><slot name="photo" /></div>
                    <template v-else><p class="border-b border-slate-200 bg-slate-50/70 px-7 py-3 text-xs leading-5 text-slate-600"><i class="fa-solid fa-user-pen mr-2 text-slate-500" aria-hidden="true"></i>These details are self-declared. Verification applies to reviewed evidence, not automatically to every profile field.</p><slot v-if="current" :section="current" /></template>
                </div>
                <footer class="flex shrink-0 items-center justify-between gap-5 border-t border-slate-200 bg-slate-50 px-7 py-4"><p class="text-xs text-slate-500">{{ active === 'photo' ? 'A photo decision does not verify the full profile.' : 'Profile details are read-only here. Review supporting documents on the verification page.' }}</p><button type="button" :disabled="busy" class="h-10 shrink-0 rounded-sm border border-slate-300 bg-white px-5 text-sm font-bold text-slate-700 hover:border-slate-950 disabled:opacity-40" @click="close">Close profile</button></footer>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.verification-profile-dialog { height: min(49rem, calc(100dvh - 3rem)); }
</style>
