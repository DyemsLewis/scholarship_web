<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { useModalAccessibility } from '../../composables/useModalAccessibility';

const props = defineProps({
    workspace: { type: String, required: true },
    autoOffer: { type: Boolean, default: false },
});
const user = window.portalUser ?? {};
const workspacePermissions = {
    programs: 'manage_programs', reviews: 'verify_applications', selection: 'manage_selection_activities',
    decisions: 'record_final_decisions', recipients: 'manage_recipients',
    monitoring: 'manage_monitoring', releases: 'manage_benefit_releases',
    'organization-profile': 'manage_profile', team: 'manage_team',
    support: 'manage_reports', billing: 'manage_billing',
};
const allowed = computed(() => user.role === 'provider' && (props.workspace === 'manager'
    ? !user.provider_workspace_url
    : Boolean(workspacePermissions[props.workspace] && user.permissions?.includes(workspacePermissions[props.workspace]))));
const id = useId();
const open = ref(false);
const dialog = ref(null);
const content = ref(null);
const guide = ref(null);
const step = ref(0);
const loading = ref(false);
const loadError = ref(false);
const progressError = ref(false);
const current = computed(() => guide.value?.steps[step.value]);
let disposed = false;
let manualInteraction = false;
let loadingRequest = null;
let progressQueue = Promise.resolve();
let latestStatus = 'seen';
const endpoint = `/provider/workspace-guides/${encodeURIComponent(props.workspace)}`;
const { handleKeydown } = useModalAccessibility(() => open.value, dialog, () => close());
watch(step, () => {
    if (!open.value) return;
    content.value?.scrollTo({ top: 0 });
    dialog.value?.focus({ preventScroll: true });
}, { flush: 'post' });

function sessionKey() {
    return `provider-guide:${user.id}:${props.workspace}:${guide.value.version}`;
}

function rememberLocally() {
    try { window.sessionStorage.setItem(sessionKey(), 'seen'); } catch { /* Server tracking still works without browser storage. */ }
}

function alreadyOpened() {
    try { return Boolean(window.sessionStorage.getItem(sessionKey())); } catch { return false; }
}

function saveProgress(status) {
    latestStatus = status;
    const version = guide.value.version;
    rememberLocally();
    progressQueue = progressQueue.then(async () => {
        try {
            await window.axios.post(`${endpoint}/progress`, { version, status }, { portalToast: false });
            progressError.value = false;
        } catch {
            progressError.value = true;
        }
    });
}

async function load() {
    if (loadingRequest) return loadingRequest;
    loading.value = true;
    loadError.value = false;
    loadingRequest = (async () => {
        try {
            const { data } = await window.axios.get(endpoint, { portalToast: false });
            if (!disposed) guide.value = data.guide;
            return data;
        } catch {
            loadError.value = true;
            return null;
        } finally {
            loading.value = false;
            loadingRequest = null;
        }
    })();
    return loadingRequest;
}

async function show() {
    manualInteraction = true;
    step.value = 0;
    open.value = true;
    if (!guide.value) await load();
    if (!disposed && guide.value) saveProgress(open.value ? 'seen' : 'dismissed');
}

async function retry() {
    await load();
    if (!disposed && guide.value) saveProgress(open.value ? 'seen' : 'dismissed');
}

function close(completed = false) {
    if (guide.value) saveProgress(completed ? 'completed' : 'dismissed');
    open.value = false;
}

onMounted(async () => {
    if (!allowed.value || !props.autoOffer) return;
    const data = await load();
    if (!disposed && props.autoOffer && !manualInteraction && data?.auto_offer && !alreadyOpened()) {
        open.value = true;
        saveProgress('seen');
    }
});
onBeforeUnmount(() => { disposed = true; });
</script>

<template>
    <button v-if="allowed" type="button" class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500" @click="show">
        <i class="fa-regular fa-circle-question text-sm" aria-hidden="true"></i>
        Workspace guide
    </button>

    <Teleport to="body">
        <div v-if="open" class="workspace-guide-overlay fixed inset-0 z-[2100] flex items-center justify-center bg-slate-950/65 p-5" @click.self="close()">
            <section ref="dialog" role="dialog" aria-modal="true" :aria-labelledby="`${id}-title`" :aria-describedby="`${id}-purpose`" tabindex="-1" class="workspace-guide-dialog flex max-h-[calc(100dvh-2.5rem)] w-full max-w-[46rem] flex-col overflow-hidden rounded-md border border-slate-200 bg-white shadow-2xl outline-none" @keydown="handleKeydown">
                <header class="flex shrink-0 items-start justify-between gap-5 border-b border-slate-200 px-7 py-5">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Workspace guide</p>
                        <h2 :id="`${id}-title`" class="mt-1 font-display text-2xl font-bold text-slate-950">{{ guide?.title || 'A quick introduction' }}</h2>
                        <p :id="`${id}-purpose`" class="mt-2 text-sm leading-6 text-slate-600">{{ guide?.purpose || 'Learn the essentials without changing your work.' }}</p>
                    </div>
                    <button type="button" aria-label="Close workspace guide" class="grid h-10 w-10 shrink-0 place-items-center rounded border border-slate-300 text-slate-600 hover:bg-slate-50" @click="close()"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </header>

                <div ref="content" class="min-h-0 overflow-y-auto px-7 py-5">
                    <p v-if="loading" role="status" class="py-12 text-center text-sm text-slate-600"><i class="fa-solid fa-spinner mr-2 animate-spin" aria-hidden="true"></i>Loading guide...</p>
                    <div v-else-if="loadError" role="alert" class="py-8 text-center">
                        <h3 class="font-bold text-slate-950">The guide could not be loaded</h3>
                        <p class="mt-2 text-sm text-slate-600">Your workspace is still available. Retry or close this guide.</p>
                        <button type="button" class="mt-5 rounded border border-slate-300 px-4 py-2 text-sm font-bold" @click="retry">Try again</button>
                    </div>
                    <template v-else-if="current">
                        <nav aria-label="Guide steps" class="mb-6 flex items-center gap-2">
                            <button v-for="(item, index) in guide.steps" :key="item.title" type="button" :aria-label="`Step ${index + 1}: ${item.title}`" :aria-current="index === step ? 'step' : undefined" class="flex h-7 flex-1 items-center rounded-sm focus-visible:outline-2 focus-visible:outline-amber-500" @click="step = index"><span :class="['h-1.5 w-full rounded-sm', index <= step ? 'bg-amber-400' : 'bg-slate-200']"></span></button>
                        </nav>
                        <div aria-live="polite" aria-atomic="true">
                            <div class="mb-4 flex items-center gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded bg-slate-950 text-amber-300" aria-hidden="true"><i :class="current.icon"></i></span>
                                <div><p class="text-[10px] font-bold uppercase tracking-widest text-slate-500">Step {{ step + 1 }} of {{ guide.steps.length }}</p><h3 class="mt-0.5 text-lg font-bold text-slate-950">{{ current.title }}</h3></div>
                            </div>
                            <ol class="divide-y divide-slate-200">
                                <li v-for="item in current.items" :key="item.label" class="py-3">
                                    <p class="text-sm font-bold text-slate-950">{{ item.label }}</p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ item.text }}</p>
                                </li>
                            </ol>
                            <p v-if="step === 0" :class="['mt-4 border-l-2 px-4 py-3 text-xs leading-5', guide.ready ? 'border-slate-300 bg-slate-50 text-slate-600' : 'border-amber-400 bg-amber-50 text-amber-900']"><strong>Before you start:</strong> {{ guide.prerequisite }}</p>
                            <p v-if="step === guide.steps.length - 1" class="mt-4 border-l-2 border-slate-300 bg-slate-50 px-4 py-3 text-xs leading-5 text-slate-600">{{ guide.scope_note }}</p>
                        </div>
                    </template>
                </div>

                <footer class="shrink-0 border-t border-slate-200 bg-slate-50 px-7 py-4">
                    <p v-if="progressError" role="status" class="mb-3 text-xs text-amber-900">Guide progress could not be saved. You can still continue. <button type="button" class="font-bold underline" @click="saveProgress(latestStatus)">Retry saving</button></p>
                    <div class="flex items-center justify-between gap-4">
                        <button type="button" class="rounded px-2 py-2 text-sm font-semibold text-slate-600 hover:text-slate-950" @click="close()">{{ guide && !loadError ? 'Skip guide' : 'Close guide' }}</button>
                        <div v-if="current && !loading && !loadError" class="flex items-center gap-3">
                            <button v-if="step > 0" type="button" class="h-10 rounded border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700" @click="step--">Back</button>
                            <button v-if="step < guide.steps.length - 1" type="button" class="h-10 rounded bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800" @click="step++">Next <i class="fa-solid fa-arrow-right ml-2 text-xs text-amber-300" aria-hidden="true"></i></button>
                            <button v-else type="button" class="h-10 rounded bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800" @click="close(true)">Got it</button>
                        </div>
                    </div>
                </footer>
            </section>
        </div>
    </Teleport>
</template>
