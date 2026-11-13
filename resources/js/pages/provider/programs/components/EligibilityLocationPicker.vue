<script setup>
import { onBeforeUnmount, ref } from 'vue';
import EligibilityOptionPicker from './EligibilityOptionPicker.vue';
import { philippineRegionOptions, provincesForRegion, citiesForLocation } from '../../../../support/philippineLocations';

const model = defineModel({ type: String, default: '' });
defineProps({ inputClass: { type: String, required: true } });
const region = ref(''); const province = ref(''); const city = ref('');
const provinces = ref([]); const cities = ref([]);
const loadingProvinces = ref(false); const loadingCities = ref(false);
const error = ref('');
let version = 0;
async function loadRegion() {
    const request = ++version;
    province.value = ''; city.value = ''; provinces.value = []; cities.value = [];
    error.value = ''; loadingCities.value = false;
    loadingProvinces.value = Boolean(region.value);
    if (!region.value) return;
    try {
        const options = await provincesForRegion(region.value);
        if (request === version) provinces.value = options;
    } catch { if (request === version) error.value = 'Location options could not be loaded. Your saved selections are unchanged.'; }
    finally { if (request === version) loadingProvinces.value = false; }
}
async function loadProvince() {
    const request = ++version;
    city.value = ''; cities.value = []; error.value = '';
    loadingCities.value = Boolean(province.value);
    if (!province.value) return;
    try {
        const options = await citiesForLocation(region.value, province.value);
        if (request === version) cities.value = options;
    } catch { if (request === version) error.value = 'City options could not be loaded. Your saved selections are unchanged.'; }
    finally { if (request === version) loadingCities.value = false; }
}
onBeforeUnmount(() => { version++; });
const nameFor = (options, code) => options.find(option => option.code === code)?.value ?? '';
</script>

<template>
    <EligibilityOptionPicker v-model="model" id="program-eligible-locations" open-label="Nationwide / no location restriction" open-value="Nationwide" :allow-custom="false">
        <template #picker="{ add }">
            <p class="text-xs leading-5 text-slate-500">Add a whole region, a province, or a city. Selecting below does not change coverage until you choose Add.</p>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label for="program-eligible-locations" class="mb-1 block text-xs font-semibold text-slate-600">Region</label>
                    <select id="program-eligible-locations" v-model="region" :class="inputClass" @change="loadRegion"><option value="">Choose region</option><option v-for="option in philippineRegionOptions" :key="option.code" :value="option.code">{{ option.label }}</option></select>
                    <button type="button" :disabled="!region" class="mt-2 text-xs font-bold text-slate-600 underline underline-offset-2 disabled:opacity-40" @click="add(nameFor(philippineRegionOptions, region))">Add region</button>
                </div>
                <div>
                    <label for="program-coverage-province" class="mb-1 block text-xs font-semibold text-slate-600">Province / Metro Manila</label>
                    <select id="program-coverage-province" v-model="province" :class="inputClass" :disabled="!region || loadingProvinces" @change="loadProvince"><option value="">{{ loadingProvinces ? 'Loading...' : 'Choose province' }}</option><option v-for="option in provinces" :key="option.code" :value="option.code">{{ option.label }}</option></select>
                    <button type="button" :disabled="!province" class="mt-2 text-xs font-bold text-slate-600 underline underline-offset-2 disabled:opacity-40" @click="add(nameFor(provinces, province))">Add province</button>
                </div>
                <div>
                    <label for="program-coverage-city" class="mb-1 block text-xs font-semibold text-slate-600">City / municipality</label>
                    <select id="program-coverage-city" v-model="city" :class="inputClass" :disabled="!province || loadingCities" @change="error = ''"><option value="">{{ loadingCities ? 'Loading...' : 'Choose city' }}</option><option v-for="option in cities" :key="option.code" :value="option.code">{{ option.label }}</option></select>
                    <button type="button" :disabled="!city" class="mt-2 text-xs font-bold text-slate-600 underline underline-offset-2 disabled:opacity-40" @click="add(nameFor(cities, city))">Add city</button>
                </div>
            </div>
            <p v-if="error" role="alert" class="text-xs text-rose-700">{{ error }} <button type="button" class="font-bold underline" @click="province ? loadProvince() : loadRegion()">Retry</button></p>
        </template>
    </EligibilityOptionPicker>
</template>
