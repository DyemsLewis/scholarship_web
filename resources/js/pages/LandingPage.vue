<script setup>
import SiteFooter from '../components/SiteFooter.vue';
import SiteNavbar from '../components/SiteNavbar.vue';

const heroImage = 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?auto=format&fit=crop&w=1800&q=85';

const scholarshipSteps = [
    {
        title: 'Choose your role',
        text: 'Applicants and providers each have a dedicated registration path.',
    },
    {
        title: 'Enter the right workspace',
        text: 'Applicants continue to scholarship access while providers use a provider dashboard.',
    },
    {
        title: 'Keep records organized',
        text: 'Admins can monitor account roles and review registered users from the admin panel.',
    },
];

const audiences = [
    {
        label: 'For applicants',
        title: 'Find and continue scholarship opportunities',
        text: 'Students can create an applicant profile, sign in, and continue their scholarship journey through the portal.',
        href: '/register',
        action: 'Register as applicant',
    },
    {
        label: 'For providers',
        title: 'Manage scholarship provider access',
        text: 'Scholarship providers can register separately and access a workspace prepared for managing scholarship programs.',
        href: '/provider/register',
        action: 'Register as provider',
    },
];
</script>

<template>
    <main class="min-h-screen bg-white text-slate-900">
        <section
            class="relative flex min-h-[86vh] flex-col overflow-hidden bg-slate-900 text-white"
            :style="{ backgroundImage: `linear-gradient(90deg, rgba(8, 20, 38, 0.84), rgba(8, 20, 38, 0.46), rgba(8, 20, 38, 0.18)), url(${heroImage})`, backgroundSize: 'cover', backgroundPosition: 'center' }"
        >
            <SiteNavbar variant="transparent" />

            <div class="relative z-10 mx-auto flex w-full max-w-6xl flex-1 items-center px-4 py-16 sm:px-6 lg:px-8">
                <div class="max-w-2xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-200">
                        Scholarship Access for Applicants and Providers
                    </p>
                    <h1 class="mt-5 font-display text-4xl leading-tight font-bold text-white sm:text-5xl lg:text-6xl">
                        Scholarship Portal
                    </h1>
                    <p class="mt-5 max-w-xl text-lg leading-8 text-slate-100">
                        A shared portal where students apply for opportunities and scholarship providers manage access to their programs.
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a
                            href="/register"
                            class="rounded-md bg-amber-300 px-5 py-3 text-center text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                        >
                            Create Account
                        </a>
                        <a
                            href="/provider"
                            class="rounded-md bg-white px-5 py-3 text-center text-sm font-bold text-slate-950 transition hover:bg-slate-100"
                        >
                            Provider Portal
                        </a>
                        <a
                            href="/login"
                            class="rounded-md border border-white/60 px-5 py-3 text-center text-sm font-bold text-white transition hover:bg-white hover:text-slate-950"
                        >
                            Sign In
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <section class="border-b border-slate-200 bg-white px-4 py-10 sm:px-6 lg:px-8">
            <div class="mx-auto grid max-w-6xl gap-6 sm:grid-cols-3">
                <div>
                    <p class="font-display text-3xl font-bold text-emerald-700">
                        01
                    </p>
                    <h2 class="mt-2 text-lg font-bold text-slate-950">
                        Account profile
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Register as an applicant or a scholarship provider.
                    </p>
                </div>

                <div>
                    <p class="font-display text-3xl font-bold text-amber-600">
                        02
                    </p>
                    <h2 class="mt-2 text-lg font-bold text-slate-950">
                        Scholarship access
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Applicants and providers enter the workspace built for their role.
                    </p>
                </div>

                <div>
                    <p class="font-display text-3xl font-bold text-sky-700">
                        03
                    </p>
                    <h2 class="mt-2 text-lg font-bold text-slate-950">
                        Admin oversight
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Admins can review users and role distribution.
                    </p>
                </div>
            </div>
        </section>

        <section class="border-b border-slate-200 bg-white px-4 py-14 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl">
                <div class="max-w-2xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-700">
                        Built for both sides
                    </p>
                    <h2 class="mt-3 font-display text-3xl font-bold text-slate-950">
                        Applicants and providers use the same portal with different roles
                    </h2>
                </div>

                <div class="mt-8 grid gap-4 md:grid-cols-2">
                    <article
                        v-for="audience in audiences"
                        :key="audience.label"
                        class="rounded-lg border border-slate-200 bg-slate-50 p-6"
                    >
                        <p class="text-sm font-bold uppercase tracking-[0.16em] text-slate-500">
                            {{ audience.label }}
                        </p>
                        <h3 class="mt-3 text-xl font-bold text-slate-950">
                            {{ audience.title }}
                        </h3>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            {{ audience.text }}
                        </p>
                        <a
                            :href="audience.href"
                            class="mt-5 inline-flex rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                        >
                            {{ audience.action }}
                        </a>
                    </article>
                </div>
            </div>
        </section>

        <section class="bg-slate-50 px-4 py-14 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl">
                <div class="max-w-2xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-700">
                        Portal Flow
                    </p>
                    <h2 class="mt-3 font-display text-3xl font-bold text-slate-950">
                        A clearer path from registration to the right dashboard
                    </h2>
                </div>

                <div class="mt-8 grid gap-4 md:grid-cols-3">
                    <article
                        v-for="step in scholarshipSteps"
                        :key="step.title"
                        class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <h3 class="text-lg font-bold text-slate-950">
                            {{ step.title }}
                        </h3>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            {{ step.text }}
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <SiteFooter variant="dark" />
    </main>
</template>
