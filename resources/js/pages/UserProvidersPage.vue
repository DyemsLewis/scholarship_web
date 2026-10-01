<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';

const appElement = document.getElementById('app');
const providerId = appElement?.dataset.providerId || null;
const isDetail = Boolean(providerId);
const isLoading = ref(true);
const errorMessage = ref('');
const providers = ref([]);
const provider = ref(null);
const summary = ref({ providers: 0, programs: 0 });
const search = ref('');
const selectedType = ref('all');
const failedLogos = ref(new Set());

const providerTypes = computed(() => [
    'all',
    ...new Set(providers.value.map((item) => item.type).filter(Boolean)),
]);
const filteredProviders = computed(() => {
    const keyword = search.value.trim().toLowerCase();

    return providers.value.filter((item) => {
        const matchesType = selectedType.value === 'all' || item.type === selectedType.value;
        const matchesSearch = !keyword || [
            item.name,
            item.type,
            item.mission,
            item.description,
            item.service_area,
            ...(item.focus_areas ?? []),
        ].filter(Boolean).some((value) => String(value).toLowerCase().includes(keyword));

        return matchesType && matchesSearch;
    });
});

function typeLabel(value) {
    return String(value || 'Scholarship provider')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function providerInitials(item) {
    return String(item?.name || 'Provider')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function hasLogo(item) {
    return Boolean(item?.logo_url) && !failedLogos.value.has(item.id);
}

function markLogoFailed(item) {
    failedLogos.value = new Set([...failedLogos.value, item.id]);
}

function websiteUrl(value) {
    const website = String(value ?? '').trim();

    if (!website) {
        return null;
    }

    return /^https?:\/\//i.test(website) ? website : `https://${website}`;
}

function focusLabel(item) {
    return item?.focus_areas?.length ? item.focus_areas.join(' / ') : 'Current scholarship programs';
}

function programTiming(program) {
    if (program.application_opens_date && new Date(`${program.application_opens_date}T00:00:00`) > new Date()) {
        return `Opens ${program.application_opens_at}`;
    }

    return program.deadline ? `Due ${program.deadline}` : 'No fixed deadline';
}

function programTimingClass(program) {
    return program.is_accepting_applications
        ? 'bg-emerald-100 text-emerald-800'
        : 'bg-sky-100 text-sky-800';
}

function resetFilters() {
    search.value = '';
    selectedType.value = 'all';
}

async function loadPage() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const endpoint = isDetail
            ? `/dashboard/providers/${providerId}/data`
            : '/dashboard/providers/data';
        const response = await window.axios.get(endpoint);

        if (isDetail) {
            provider.value = response.data.provider;
        } else {
            providers.value = response.data.providers ?? [];
            summary.value = response.data.summary ?? { providers: 0, programs: 0 };
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load scholarship providers.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadPage);
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <section class="student-page">
            <div class="student-container">
                <a v-if="isDetail" href="/dashboard/providers" class="mb-4 inline-flex items-center gap-2 text-sm font-bold text-slate-600 transition hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to providers
                </a>

                <ApplicantPageHeader
                    :eyebrow="isDetail ? 'Scholarship provider' : 'Provider directory'"
                    :title="isDetail ? (provider?.name || 'Provider profile') : 'Find a scholarship provider'"
                    :description="isDetail ? (provider?.mission || 'Review this organization and its current scholarship programs.') : 'Browse verified organizations and the programs they currently offer.'"
                    icon="fa-solid fa-building-shield"
                />

                <div v-if="isLoading" class="student-card mt-5 p-6 text-sm text-slate-500">
                    Loading providers...
                </div>

                <div v-else-if="errorMessage" class="mt-5 rounded-md border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-700">
                    {{ errorMessage }}
                </div>

                <template v-else-if="!isDetail">
                    <section class="student-card mt-5 overflow-hidden">
                        <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
                            <label class="relative min-w-0 flex-1">
                                <span class="sr-only">Search providers</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400" aria-hidden="true"></i>
                                <input v-model="search" type="search" placeholder="Search provider, service area, or focus" class="w-full rounded-md border border-slate-300 bg-white py-3 pl-10 pr-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                            </label>
                            <select v-model="selectedType" aria-label="Filter by provider type" class="rounded-md border border-slate-300 bg-white px-3.5 py-3 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:ring-3 focus:ring-amber-100 sm:w-56">
                                <option v-for="type in providerTypes" :key="type" :value="type">{{ type === 'all' ? 'All provider types' : typeLabel(type) }}</option>
                            </select>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-slate-200 bg-slate-50 px-4 py-3 text-xs font-semibold text-slate-500">
                            <span><strong class="text-slate-950">{{ summary.providers }}</strong> verified provider{{ summary.providers === 1 ? '' : 's' }}</span>
                            <span><strong class="text-slate-950">{{ summary.programs }}</strong> current program{{ summary.programs === 1 ? '' : 's' }}</span>
                        </div>
                    </section>

                    <section class="mt-5 space-y-3">
                        <div v-if="filteredProviders.length === 0" class="student-card p-6">
                            <p class="font-bold text-slate-950">{{ providers.length ? 'No providers match your search' : 'No verified providers yet' }}</p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">{{ providers.length ? 'Try a broader name, location, or provider type.' : 'Organizations will appear here after platform verification.' }}</p>
                            <button v-if="providers.length" type="button" class="mt-4 rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white" @click="resetFilters">Clear search</button>
                        </div>

                        <article v-for="item in filteredProviders" :key="item.id" class="student-card overflow-hidden">
                            <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:p-5">
                                <img v-if="hasLogo(item)" :src="item.logo_url" :alt="`${item.name} logo`" class="h-16 w-16 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200" @error="markLogoFailed(item)">
                                <div v-else class="grid h-16 w-16 shrink-0 place-items-center rounded-md bg-slate-950 text-sm font-black tracking-[0.08em] text-white">{{ providerInitials(item) }}</div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="font-display text-xl font-bold text-slate-950">{{ item.name }}</h2>
                                        <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-100 px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em] text-emerald-800"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>Verified</span>
                                    </div>
                                    <p class="mt-1 text-xs font-bold uppercase tracking-[0.12em] text-slate-500">{{ typeLabel(item.type) }}<span v-if="item.year_established"> | Since {{ item.year_established }}</span></p>
                                    <p class="mt-2 line-clamp-2 max-w-4xl text-sm leading-6 text-slate-600">{{ item.mission || item.description || 'This provider has current scholarship opportunities.' }}</p>
                                    <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs font-semibold text-slate-500">
                                        <span v-if="item.service_area" class="inline-flex items-center gap-2"><i class="fa-solid fa-map-location-dot text-amber-700" aria-hidden="true"></i>{{ item.service_area }}</span>
                                        <span class="inline-flex items-center gap-2"><i class="fa-solid fa-graduation-cap text-amber-700" aria-hidden="true"></i>{{ item.programs_count }} program{{ item.programs_count === 1 ? '' : 's' }}</span>
                                        <span class="inline-flex items-center gap-2"><i class="fa-solid fa-bullseye text-amber-700" aria-hidden="true"></i>{{ focusLabel(item) }}</span>
                                    </div>
                                </div>

                                <a :href="`/dashboard/providers/${item.id}`" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800">
                                    View provider
                                    <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                </a>
                            </div>
                        </article>
                    </section>
                </template>

                <template v-else-if="provider">
                    <section class="student-card mt-5 overflow-hidden">
                        <div class="flex flex-col gap-4 border-b border-slate-200 p-5 sm:flex-row sm:items-center">
                            <img v-if="hasLogo(provider)" :src="provider.logo_url" :alt="`${provider.name} logo`" class="h-20 w-20 shrink-0 rounded-md bg-white object-contain p-2 ring-1 ring-slate-200" @error="markLogoFailed(provider)">
                            <div v-else class="grid h-20 w-20 shrink-0 place-items-center rounded-md bg-slate-950 text-lg font-black tracking-[0.08em] text-white">{{ providerInitials(provider) }}</div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">{{ typeLabel(provider.type) }}</p>
                                    <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-100 px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em] text-emerald-800"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>Verified provider</span>
                                </div>
                                <h2 class="mt-1 font-display text-2xl font-bold text-slate-950">{{ provider.name }}</h2>
                                <p v-if="provider.year_established" class="mt-1 text-sm font-semibold text-slate-500">Established {{ provider.year_established }}</p>
                            </div>
                        </div>

                        <dl class="divide-y divide-slate-200">
                            <div v-if="provider.description" class="p-5">
                                <dt class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">About</dt>
                                <dd class="mt-2 max-w-5xl whitespace-pre-line text-sm leading-6 text-slate-700">{{ provider.description }}</dd>
                            </div>
                            <div v-if="provider.service_area" class="grid gap-2 p-5 sm:grid-cols-[11rem_minmax(0,1fr)]">
                                <dt class="font-bold text-slate-950"><i class="fa-solid fa-map-location-dot mr-2 text-amber-700" aria-hidden="true"></i>Service area</dt>
                                <dd class="text-sm leading-6 text-slate-600">{{ provider.service_area }}</dd>
                            </div>
                            <div class="grid gap-2 p-5 sm:grid-cols-[11rem_minmax(0,1fr)]">
                                <dt class="font-bold text-slate-950"><i class="fa-solid fa-address-card mr-2 text-amber-700" aria-hidden="true"></i>Public contact</dt>
                                <dd class="flex flex-wrap gap-x-5 gap-y-2 text-sm text-slate-600">
                                    <span v-if="provider.contact_department" class="font-bold text-slate-800">{{ provider.contact_department }}</span>
                                    <a v-if="provider.contact_email" :href="`mailto:${provider.contact_email}`" class="hover:text-slate-950">{{ provider.contact_email }}</a>
                                    <a v-if="provider.contact_number" :href="`tel:${provider.contact_number}`" class="hover:text-slate-950">{{ provider.contact_number }}</a>
                                    <span v-if="provider.office_hours">{{ provider.office_hours }}</span>
                                    <span v-if="!provider.contact_department && !provider.contact_email && !provider.contact_number && !provider.office_hours">No public contact details listed.</span>
                                </dd>
                            </div>
                            <div v-if="provider.website || provider.address" class="grid gap-2 p-5 sm:grid-cols-[11rem_minmax(0,1fr)]">
                                <dt class="font-bold text-slate-950"><i class="fa-solid fa-building mr-2 text-amber-700" aria-hidden="true"></i>Organization</dt>
                                <dd class="flex flex-wrap gap-x-5 gap-y-2 text-sm text-slate-600">
                                    <a v-if="websiteUrl(provider.website)" :href="websiteUrl(provider.website)" target="_blank" rel="noopener noreferrer" class="font-bold text-sky-700 hover:text-sky-900">Visit website <i class="fa-solid fa-arrow-up-right-from-square ml-1 text-[10px]" aria-hidden="true"></i></a>
                                    <span v-if="provider.address">{{ provider.address }}</span>
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section class="mt-6">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div><p class="student-kicker">Current opportunities</p><h2 class="mt-1 font-display text-2xl font-bold text-slate-950">Scholarships from this provider</h2></div>
                            <span class="rounded-md bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700">{{ provider.programs_count }} program{{ provider.programs_count === 1 ? '' : 's' }}</span>
                        </div>

                        <div v-if="!provider.programs?.length" class="student-card mt-4 p-6 text-sm text-slate-600">This provider has no current public programs.</div>

                        <div v-else class="mt-4 space-y-3">
                            <article v-for="program in provider.programs" :key="program.id" class="student-card overflow-hidden">
                                <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:p-5">
                                    <img :src="program.image_url" :alt="program.title" class="h-16 w-16 shrink-0 rounded-md bg-slate-50 object-contain p-1.5 ring-1 ring-slate-200">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">{{ program.category || 'Scholarship program' }}</span>
                                            <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', programTimingClass(program)]">{{ programTiming(program) }}</span>
                                        </div>
                                        <h3 class="mt-1 font-display text-lg font-bold text-slate-950">{{ program.title }}</h3>
                                        <p v-if="program.description" class="mt-1 line-clamp-1 text-sm text-slate-600">{{ program.description }}</p>
                                        <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs font-semibold text-slate-500">
                                            <span>{{ program.eligibility_match?.score ?? 0 }}% profile match</span>
                                            <span>{{ program.benefit_summary || 'Benefits listed in program' }}</span>
                                        </div>
                                    </div>
                                    <a :href="`/dashboard/scholarships/${program.id}`" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800">View scholarship <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                                </div>
                            </article>
                        </div>
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
