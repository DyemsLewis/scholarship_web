<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const isLoading = ref(true);
const isSubmitting = ref(false);
const errorMessage = ref('');
const workspace = ref(null);
const categories = ref([]);
const programs = ref([]);
const attachment = ref(null);
const form = reactive({ category: '', scholarship_id: '', context: '', subject: '', description: '' });
const attachmentLabel = computed(() => attachment.value?.name || 'No file selected');

async function loadForm() {
    try {
        const response = await window.axios.get('/provider/workspaces/support/report-platform-issue/data');
        workspace.value = response.data.workspace;
        categories.value = response.data.categories ?? [];
        programs.value = response.data.programs ?? [];
        form.category = categories.value[0]?.value || '';
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The platform report form could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

function selectAttachment(event) {
    attachment.value = event.target.files?.[0] ?? null;
}

async function submitReport() {
    isSubmitting.value = true;
    errorMessage.value = '';
    const payload = new FormData();
    payload.append('category', form.category);
    payload.append('subject', form.subject.trim());
    payload.append('description', form.description.trim());
    if (form.scholarship_id) payload.append('scholarship_id', form.scholarship_id);
    if (form.context.trim()) payload.append('context', form.context.trim());
    if (attachment.value) payload.append('attachment_file', attachment.value);

    try {
        await window.axios.post('/provider/reports', payload);
        window.location.assign('/provider/workspaces/support/platform');
    } catch (error) {
        errorMessage.value = Object.values(error.response?.data?.errors ?? {})[0]?.[0]
            || error.response?.data?.message
            || 'The platform report could not be sent.';
    } finally {
        isSubmitting.value = false;
    }
}

onMounted(loadForm);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="platform-report-title">
            <div class="mx-auto max-w-[94rem]">
                <a href="/provider/workspaces/support/platform" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to platform reports</a>

                <header class="mt-4 border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-5 shadow-sm">
                    <div class="flex items-center gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Platform support</p><h1 id="platform-report-title" class="mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">Report a platform issue</h1><p class="mt-1 text-sm text-slate-600">Describe one issue so platform support can investigate it clearly.</p></div></div>
                </header>

                <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-20 text-center shadow-sm"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading report form...</p></div>

                <form v-else class="mt-4 border border-slate-200 bg-white shadow-sm" @submit.prevent="submitReport">
                    <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-6 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>

                    <section class="border-b border-slate-200" aria-labelledby="report-context-heading">
                        <header class="flex items-start gap-4 bg-slate-50 px-6 py-4">
                            <span class="grid h-8 w-8 shrink-0 place-items-center bg-slate-950 text-xs font-black text-white">1</span>
                            <div><h2 id="report-context-heading" class="text-base font-bold text-slate-950">Issue context</h2><p class="mt-1 text-xs text-slate-500">Identify where the problem occurred.</p></div>
                        </header>
                        <div class="grid grid-cols-3 gap-5 px-6 py-5">
                            <label class="block"><span class="text-sm font-bold text-slate-800">Issue type</span><select v-model="form.category" required class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 text-sm outline-none focus:border-slate-950"><option v-for="category in categories" :key="category.value" :value="category.value">{{ category.label }}</option></select></label>
                            <label class="block"><span class="text-sm font-bold text-slate-800">Related program <span class="font-normal text-slate-400">(optional)</span></span><select v-model="form.scholarship_id" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 text-sm outline-none focus:border-slate-950"><option value="">General platform issue</option><option v-for="program in programs" :key="program.id" :value="program.id">{{ program.title }}</option></select></label>
                            <label class="block"><span class="text-sm font-bold text-slate-800">Affected page <span class="font-normal text-slate-400">(optional)</span></span><input v-model="form.context" type="text" maxlength="255" class="mt-2 h-11 w-full border border-slate-300 px-4 text-sm outline-none focus:border-slate-950" placeholder="Example: Applications page"></label>
                        </div>
                    </section>

                    <section class="border-b border-slate-200" aria-labelledby="report-description-heading">
                        <header class="flex items-start gap-4 bg-slate-50 px-6 py-4">
                            <span class="grid h-8 w-8 shrink-0 place-items-center bg-slate-950 text-xs font-black text-white">2</span>
                            <div><h2 id="report-description-heading" class="text-base font-bold text-slate-950">What happened</h2><p class="mt-1 text-xs text-slate-500">State the problem and the result you expected.</p></div>
                        </header>
                        <div class="space-y-5 px-6 py-5">
                            <label class="block"><span class="text-sm font-bold text-slate-800">Subject</span><input v-model="form.subject" type="text" required minlength="5" maxlength="150" class="mt-2 h-11 w-full border border-slate-300 px-4 text-sm outline-none focus:border-slate-950" placeholder="Briefly name the problem"></label>
                            <label class="block"><span class="text-sm font-bold text-slate-800">Description</span><textarea v-model="form.description" required minlength="10" maxlength="2000" rows="7" class="mt-2 w-full border border-slate-300 px-4 py-3 text-sm leading-6 outline-none focus:border-slate-950" placeholder="Explain what you tried, what happened, and what should have happened."></textarea></label>
                        </div>
                    </section>

                    <section aria-labelledby="report-evidence-heading">
                        <header class="flex items-start gap-4 bg-slate-50 px-6 py-4">
                            <span class="grid h-8 w-8 shrink-0 place-items-center bg-slate-950 text-xs font-black text-white">3</span>
                            <div><h2 id="report-evidence-heading" class="text-base font-bold text-slate-950">Supporting file <span class="font-normal text-slate-400">(optional)</span></h2><p class="mt-1 text-xs text-slate-500">Attach one screenshot or document when it helps explain the issue.</p></div>
                        </header>
                        <div class="flex items-center gap-4 px-6 py-5">
                            <label class="inline-flex h-11 cursor-pointer items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-800 hover:border-slate-950"><i class="fa-solid fa-paperclip text-slate-500" aria-hidden="true"></i>Choose file<input type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" class="sr-only" @change="selectAttachment"></label>
                            <div class="min-w-0"><p class="truncate text-sm font-semibold text-slate-700">{{ attachmentLabel }}</p><p class="mt-1 text-xs text-slate-500">JPG, PNG, WEBP, or PDF up to 5 MB.</p></div>
                        </div>
                    </section>

                    <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-6 py-4">
                        <p class="text-xs font-semibold text-slate-500">Sent as {{ workspace?.organization_name }}</p>
                        <div class="flex gap-3"><a href="/provider/workspaces/support/platform" class="inline-flex h-11 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950"><i class="fa-solid fa-xmark text-[10px]" aria-hidden="true"></i>Cancel</a><button type="submit" :disabled="isSubmitting" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-40"><i :class="['fa-solid', isSubmitting ? 'fa-spinner fa-spin' : 'fa-paper-plane']" aria-hidden="true"></i>{{ isSubmitting ? 'Sending...' : 'Send report' }}</button></div>
                    </footer>
                </form>
            </div>
        </section>
    </main>
</template>
