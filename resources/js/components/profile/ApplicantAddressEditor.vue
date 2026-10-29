<script setup>
import { findPhilippineRegion, philippineRegionOptions } from '../../support/philippineLocations';
import { disclosureSummary, inputClass, labelClass, optionalLabel, secondaryButton, sectionTitle } from '../../support/profileEditorStyles';

const form = defineModel({ type: Object, required: true });
defineProps({
    provinceOptions: { type: Array, required: true },
    cityOptions: { type: Array, required: true },
    loadingProvinces: { type: Boolean, default: false },
    loadingCities: { type: Boolean, default: false },
    optionsError: { type: String, default: '' },
    hasPin: { type: Boolean, default: false },
    locationMessage: { type: String, default: '' },
});
defineEmits(['region-change', 'province-change', 'city-change', 'retry-options', 'open-map']);
</script>

<template>
    <div id="profile-location" data-address-editor class="divide-y divide-slate-200 px-4 sm:px-6">
        <section class="py-4" aria-labelledby="address-general-title">
            <h3 id="address-general-title" :class="sectionTitle">General location</h3>
            <p class="mt-1 text-xs leading-5 text-slate-500">Your city, province, and region are used for residency checks.</p>
            <div class="mt-4 grid items-start gap-4 sm:grid-cols-3">
                <div class="min-w-0">
                    <label :class="labelClass" for="profile-region">Region</label>
                    <select id="profile-region" v-model="form.region" required :class="inputClass" @change="$emit('region-change')"><option value="">Select region</option><option v-if="form.region && !findPhilippineRegion(form.region)" :value="form.region">{{ form.region }}</option><option v-for="region in philippineRegionOptions" :key="region.code" :value="region.value">{{ region.label }}</option></select>
                </div>
                <div class="min-w-0">
                    <label :class="labelClass" for="profile-province">Province</label>
                    <select id="profile-province" v-model="form.province" required :class="inputClass" :disabled="!form.region || loadingProvinces" @change="$emit('province-change')"><option value="">{{ loadingProvinces ? 'Loading provinces...' : 'Select province' }}</option><option v-if="form.province && !provinceOptions.some((option) => option.value === form.province)" :value="form.province">{{ form.province }}</option><option v-for="province in provinceOptions" :key="province.code" :value="province.value">{{ province.label }}</option></select>
                </div>
                <div class="min-w-0">
                    <label :class="labelClass" for="profile-city">City / municipality</label>
                    <select id="profile-city" v-model="form.city" required :class="inputClass" :disabled="!form.province || loadingCities" @change="$emit('city-change')"><option value="">{{ loadingCities ? 'Loading cities...' : 'Select city / municipality' }}</option><option v-if="form.city && !cityOptions.some((option) => option.value === form.city)" :value="form.city">{{ form.city }}</option><option v-for="city in cityOptions" :key="city.code" :value="city.value">{{ city.label }}</option></select>
                </div>
            </div>
            <div v-if="optionsError" class="mt-3 border-l-2 border-rose-500 pl-3" role="alert"><p class="text-xs leading-5 text-rose-700">{{ optionsError }}</p><button type="button" class="mt-1 text-xs font-bold text-rose-800 underline underline-offset-2 disabled:opacity-50" :disabled="loadingProvinces || loadingCities" @click="$emit('retry-options')">Try loading locations again</button></div>
        </section>

        <details data-address-details class="group py-4" :open="Boolean(form.barangay || form.address)">
            <summary :class="disclosureSummary"><span>Address details <span :class="optionalLabel">(optional)</span></span><i class="fa-solid fa-chevron-down text-[10px] text-slate-500 transition group-open:rotate-180" aria-hidden="true"></i></summary>
            <p class="mt-1 text-xs leading-5 text-slate-500">A complete address is not required. Some providers may request it for verification.</p>
            <div class="mt-4 grid items-start gap-4 sm:grid-cols-3">
                <div class="min-w-0"><label :class="labelClass" for="profile-barangay">Barangay</label><input id="profile-barangay" v-model="form.barangay" maxlength="255" placeholder="Barangay" :class="inputClass"></div>
                <div class="min-w-0 sm:col-span-2"><label :class="labelClass" for="profile-address">Street / home address</label><input id="profile-address" v-model="form.address" maxlength="500" placeholder="Street and house number" :class="inputClass"></div>
            </div>
        </details>

        <section class="py-4" aria-labelledby="address-pin-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h3 id="address-pin-title" :class="sectionTitle">Approximate map pin <span :class="optionalLabel">(optional)</span></h3>
                    <p class="mt-1 flex items-center gap-1.5 text-xs leading-5 text-slate-500"><i :class="hasPin ? 'fa-solid fa-location-dot text-slate-700' : 'fa-regular fa-map'" aria-hidden="true"></i>{{ hasPin ? 'Pin selected in this form' : 'No pin selected' }}</p>
                </div>
                <button type="button" data-profile-map-action :class="secondaryButton" @click="$emit('open-map')">{{ hasPin ? 'Review map pin' : 'Set map pin' }}<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></button>
            </div>
            <p class="mt-3 text-xs leading-5 text-slate-500">Choose a nearby point for distance estimates; it does not need to identify your exact home.</p>
            <p v-if="locationMessage" class="mt-2 text-xs font-semibold leading-5 text-slate-700" role="status">{{ locationMessage }}</p>
            <p class="mt-3 text-xs leading-5 text-slate-500">Your home location is not shown on your public profile.</p>
        </section>
    </div>
</template>
