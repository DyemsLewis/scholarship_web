<script setup>
import SiteFooter from '../components/SiteFooter.vue';
import SiteNavbar from '../components/SiteNavbar.vue';

const sections = [
    {
        title: 'Who can use the platform',
        text: 'Applicants, parents or guardians supporting younger learners, verified scholarship providers, and portal admins may use the system for scholarship discovery, matching, application review, and scholarship management.',
    },
    {
        title: 'Accuracy of information',
        text: 'Users should provide truthful account, profile, document, scholarship, and application details. False, altered, incomplete, or unauthorized information may affect account access, application review, or provider approval.',
    },
    {
        title: 'Privacy and data use',
        text: 'The platform stores account, profile, document, location, scholarship activity, and application data so the system can provide eligibility matching, notifications, review workflows, and audit history.',
    },
    {
        title: 'Documents and verification',
        text: 'Uploaded student and provider documents should be readable and authorized. Documents may be viewed by admins or providers only when needed for verification, scholarship review, or application processing.',
    },
    {
        title: 'Decision support system',
        text: 'DSS scores and recommendations are guidance only. They help applicants and reviewers understand possible fit, but scholarship providers still make the final application decision.',
    },
    {
        title: 'Provider responsibilities',
        text: 'Providers are responsible for accurate scholarship details, lawful use of applicant data, clear requirements, fair review practices, and honoring the final terms they publish or communicate.',
    },
];
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_55%,_#e7edf4_100%)] text-slate-900">
        <SiteNavbar />

        <section class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
            <header class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <p class="text-sm font-bold uppercase tracking-[0.2em] text-amber-700">
                    Terms and Privacy Notice
                </p>
                <h1 class="mt-3 font-display text-3xl font-bold text-slate-950 sm:text-4xl">
                    Scholarship Portal terms
                </h1>
                <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                    This page explains the practical rules users accept when creating accounts, uploading documents, submitting applications, or publishing scholarship programs. It is intended for system use and should still be reviewed before public deployment.
                </p>
            </header>

            <section class="mt-6 grid gap-4 md:grid-cols-2">
                <article
                    v-for="section in sections"
                    :key="section.title"
                    class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <h2 class="text-lg font-bold text-slate-950">
                        {{ section.title }}
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        {{ section.text }}
                    </p>
                </article>
            </section>

            <section class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-slate-700">
                <p class="font-bold text-slate-950">
                    Important note
                </p>
                <p class="mt-1">
                    Before real hosting or live use, replace this practical notice with a final Terms and Privacy Policy reviewed for your school, organization, provider process, and applicable privacy requirements.
                </p>
            </section>
        </section>

        <SiteFooter />
    </main>
</template>
