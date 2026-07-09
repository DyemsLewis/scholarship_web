<script setup>
import SiteFooter from '../components/SiteFooter.vue';
import SiteNavbar from '../components/SiteNavbar.vue';

const sections = [
    {
        title: 'Who can use the platform',
        text: 'Applicants, parents or guardians supporting younger learners, verified scholarship providers, and portal admins may use the system for scholarship discovery, matching, application review, and scholarship management.',
        icon: 'fa-solid fa-users',
    },
    {
        title: 'Accuracy of information',
        text: 'Users should provide truthful account, profile, document, scholarship, and application details. False, altered, incomplete, or unauthorized information may affect account access, application review, or provider approval.',
        icon: 'fa-solid fa-circle-check',
    },
    {
        title: 'Privacy and data use',
        text: 'The platform stores account, profile, document, location, scholarship activity, and application data so the system can provide eligibility matching, notifications, review workflows, and audit history.',
        icon: 'fa-solid fa-shield-halved',
    },
    {
        title: 'Documents and verification',
        text: 'Uploaded student and provider documents should be readable and authorized. Documents may be viewed by admins or providers only when needed for verification, scholarship review, or application processing.',
        icon: 'fa-solid fa-file-shield',
    },
    {
        title: 'Decision support system',
        text: 'DSS scores and recommendations are guidance only. They help applicants and reviewers understand possible fit, but scholarship providers still make the final application decision.',
        icon: 'fa-solid fa-scale-balanced',
    },
    {
        title: 'Provider responsibilities',
        text: 'Providers are responsible for accurate scholarship details, lawful use of applicant data, clear requirements, fair review practices, and honoring the final terms they publish or communicate.',
        icon: 'fa-solid fa-building-columns',
    },
];

const quickNotes = [
    'Create accounts only with accurate and authorized information.',
    'Upload documents only if you are allowed to submit them.',
    'Use DSS results as guidance, not as the final decision.',
];

function assetUrl(path) {
    return window.appAssetUrl ? window.appAssetUrl(path) : path;
}
</script>

<template>
    <main class="min-h-screen bg-white text-slate-900">
        <section class="relative overflow-hidden bg-slate-950 text-white">
            <div
                class="absolute inset-0 bg-cover bg-center opacity-45"
                :style="{ backgroundImage: `url('${assetUrl('/images/application-documents.jpg')}')` }"
            ></div>
            <div class="absolute inset-0 bg-[linear-gradient(90deg,_rgba(8,20,38,0.96),_rgba(8,20,38,0.8),_rgba(8,20,38,0.38))]"></div>
            <div class="absolute inset-x-0 bottom-0 h-28 bg-gradient-to-t from-slate-950 to-transparent"></div>

            <SiteNavbar variant="transparent" show-icons />

            <div class="relative z-10 mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
                <div class="max-w-3xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-200">
                        Terms and Privacy Notice
                    </p>
                    <h1 class="mt-5 font-display text-4xl font-bold leading-tight text-white sm:text-5xl">
                        Scholarship Portal terms
                    </h1>
                    <p class="mt-5 max-w-2xl text-base leading-8 text-slate-100">
                        Practical rules for creating accounts, uploading documents, submitting applications, and publishing scholarship programs in the portal.
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a
                            href="/register"
                            class="rounded-md bg-amber-300 px-5 py-3 text-center text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                        >
                            Create account
                        </a>
                        <a
                            href="/"
                            class="rounded-md bg-white px-5 py-3 text-center text-sm font-bold text-slate-950 transition hover:bg-slate-100"
                        >
                            Back to home
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <section class="border-b border-slate-200 bg-white px-4 py-10 sm:px-6 lg:px-8">
            <div class="mx-auto grid max-w-6xl gap-4 md:grid-cols-[0.9fr_1.1fr] md:items-start">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                        Before using the portal
                    </p>
                    <h2 class="mt-3 font-display text-3xl font-bold text-slate-950">
                        Keep the scholarship process clear and responsible
                    </h2>
                </div>

                <div class="rounded-lg border border-slate-200 bg-slate-50 p-5">
                    <div class="grid gap-3">
                        <div
                            v-for="note in quickNotes"
                            :key="note"
                            class="flex gap-3 rounded-md bg-white p-3 text-sm leading-6 text-slate-700 ring-1 ring-slate-200"
                        >
                            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-amber-100 text-amber-800">
                                <i class="fa-solid fa-check text-xs"></i>
                            </span>
                            <span>{{ note }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-slate-50 px-4 py-14 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl">
                <div class="max-w-2xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                        User Agreement
                    </p>
                    <h2 class="mt-3 font-display text-3xl font-bold text-slate-950">
                        What the terms cover
                    </h2>
                    <p class="mt-3 text-sm leading-6 text-slate-600">
                        These points explain how the system should be used by applicants, guardians, providers, and admins.
                    </p>
                </div>

                <div class="mt-8 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <article
                        v-for="section in sections"
                        :key="section.title"
                        class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <span class="flex h-11 w-11 items-center justify-center rounded-md bg-slate-950 text-amber-200">
                            <i :class="[section.icon, 'text-sm']"></i>
                        </span>
                        <h3 class="mt-4 text-lg font-bold leading-6 text-slate-950">
                            {{ section.title }}
                        </h3>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            {{ section.text }}
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <section class="border-t border-slate-200 bg-white px-4 py-12 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl rounded-lg border border-amber-200 bg-amber-50 p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="max-w-3xl">
                        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                            Important note
                        </p>
                        <h2 class="mt-2 text-xl font-bold text-slate-950">
                            Review before real hosting
                        </h2>
                        <p class="mt-2 text-sm leading-6 text-slate-700">
                            Before live deployment, replace this practical notice with a final Terms and Privacy Policy reviewed for your school, organization, provider process, and applicable privacy requirements.
                        </p>
                    </div>
                    <a
                        href="/login"
                        class="rounded-md bg-slate-900 px-5 py-3 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                    >
                        Continue to login
                    </a>
                </div>
            </div>
        </section>

        <SiteFooter variant="dark" />
    </main>
</template>
