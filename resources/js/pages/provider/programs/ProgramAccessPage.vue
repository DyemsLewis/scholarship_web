<script setup>
import ProviderSidebar from '../ProviderSidebar.vue';

const access = JSON.parse(document.getElementById('app')?.dataset.programAccess || '{}');
const title = !access.email_verified ? 'Verify the organization email first'
    : access.verification_status === 'rejected' ? 'Organization verification needs corrections'
        : 'Organization verification is still pending';
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />
        <section class="student-page" aria-labelledby="program-access-title">
            <div class="provider-program-workspace mx-auto w-full max-w-[94rem]">
                <a href="/provider/programs" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to programs</a>
                <header class="mt-4 flex items-center gap-4 border border-slate-200 border-t-2 border-t-slate-950 bg-white px-6 py-5 shadow-sm">
                    <span class="grid h-11 w-11 shrink-0 place-items-center bg-slate-950 text-amber-300" aria-hidden="true"><i class="fa-solid fa-graduation-cap"></i></span>
                    <div><p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Program workspace</p><h1 id="program-access-title" class="mt-1 font-display text-2xl font-bold text-slate-950">{{ access.is_edit ? 'Edit scholarship program' : 'Create a scholarship program' }}</h1><p class="mt-1 text-sm text-slate-600">{{ access.organization }}</p></div>
                </header>
                <section data-program-access-notice class="mt-4 border border-slate-200 bg-white shadow-sm" aria-labelledby="verification-access-title">
                    <div class="flex items-start gap-4 border-b border-slate-200 px-6 py-6">
                        <span class="grid h-10 w-10 shrink-0 place-items-center bg-amber-100 text-amber-800" aria-hidden="true"><i class="fa-solid fa-building-shield"></i></span>
                        <div><h2 id="verification-access-title" class="text-lg font-bold text-slate-950">{{ title }}</h2><p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Program creation and editing require a verified organization email and administrator approval of the organization. Your workspace permissions are unchanged.</p></div>
                    </div>
                    <div class="px-6 py-5">
                        <h3 class="text-sm font-bold text-slate-950">What to do next</h3>
                        <p v-if="!access.email_verified" class="mt-2 text-sm leading-6 text-slate-600">The organization owner must verify the email on the main organization account. Verifying a staff account does not verify the organization email.</p>
                        <p v-if="access.verification_status !== 'approved'" class="mt-2 text-sm leading-6 text-slate-600">{{ access.can_manage_profile ? 'Review the legal verification page, upload or replace the requested proof, and wait for the final account decision from the review officer.' : 'Ask your organization profile manager or representative to complete legal verification. A platform review officer must then approve the organization account.' }}</p>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Once those checks are complete, reopen this page to continue. Existing programs remain in the directory.</p>
                        <div class="mt-5 flex items-center gap-3"><a v-if="access.can_manage_profile" href="/provider/workspaces/organization-profile/verification" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white"><i class="fa-solid fa-file-shield text-xs text-amber-300" aria-hidden="true"></i>Open organization verification</a><a href="/provider/programs" class="inline-flex h-11 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700">View programs<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></div>
                    </div>
                </section>
            </div>
        </section>
    </main>
</template>
