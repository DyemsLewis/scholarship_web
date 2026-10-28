<script setup>
import LocationMapLink from '../LocationMapLink.vue';

defineProps({ sections: { type: Array, default: () => [] } });
defineEmits(['edit']);
const hasValue = (value) => value !== null && value !== undefined && String(value).trim() !== '';
const spanClasses = { two: 'sm:col-span-2 lg:col-span-2', full: 'sm:col-span-2 lg:col-span-3' };
</script>

<template>
    <div data-profile-details class="divide-y divide-slate-200">
        <section v-for="section in sections" :key="section.id" :data-profile-section="section.id" :aria-labelledby="`profile-record-${section.id}-title`" class="px-5 py-5 sm:px-6 sm:py-6">
            <header class="flex items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-100 text-sm text-slate-700"><i :class="[section.icon, 'leading-none']" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <h3 :id="`profile-record-${section.id}-title`" class="text-base font-bold leading-5 text-slate-950">{{ section.title }}</h3>
                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ section.description }}</p>
                    </div>
                </div>
                <button type="button" class="inline-flex min-h-9 shrink-0 items-center justify-center gap-2 rounded-sm border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:border-slate-500 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2" :aria-label="`Edit ${section.title.toLowerCase()}`" @click="$emit('edit', section.id)"><i class="fa-solid fa-pen text-[10px] leading-none" aria-hidden="true"></i>Edit</button>
            </header>

            <div class="mt-5 space-y-5">
                <div v-for="group in section.groups" :key="group.id" :data-profile-group="group.id" :class="group.title ? 'border-t border-slate-200 pt-5' : ''">
                    <div v-if="group.title" class="mb-4">
                        <h4 class="text-sm font-bold text-slate-800">{{ group.title }}</h4>
                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ group.description }}</p>
                    </div>
                    <dl class="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3" data-profile-field-grid>
                        <div v-for="item in group.items" :key="item.key" :data-profile-field="item.key" :class="['min-w-0', spanClasses[item.span] || '']">
                            <dt class="text-xs font-medium leading-5 text-slate-500">{{ item.label }}</dt>
                            <dd class="mt-1">
                                <span :class="['block whitespace-pre-line break-words text-sm leading-6', hasValue(item.value) ? 'font-semibold text-slate-950' : 'text-slate-400']">{{ hasValue(item.value) ? item.value : 'Not provided' }}</span>
                                <span v-if="item.detail" class="mt-0.5 block break-words text-xs leading-5 text-slate-500">{{ item.detail }}</span>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <p v-if="section.note" class="mt-4 text-xs leading-5 text-slate-500">{{ section.note }}</p>
            <div v-if="section.map" class="mt-5 flex flex-col gap-2 border-t border-slate-200 pt-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6" data-profile-saved-map>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-slate-700">{{ section.map.hasPin ? 'Approximate location pinned' : 'General location' }}</p>
                    <p class="mt-1 max-w-2xl text-xs leading-5 text-slate-500">A complete street address is optional. A provider may request it later for verification.</p>
                </div>
                <LocationMapLink :address="section.map.address" :latitude="section.map.latitude" :longitude="section.map.longitude" title="Approximate home location" />
            </div>
        </section>
    </div>
</template>
