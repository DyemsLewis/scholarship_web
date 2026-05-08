<script setup>
const heroImage = 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?auto=format&fit=crop&w=1800&q=85';

const scholarshipSteps = [
    {
        title: 'Create your profile',
        text: 'Keep your student information, contact details, and application identity in one place.',
    },
    {
        title: 'Submit requirements',
        text: 'Prepare the documents and details needed for scholarship review.',
    },
    {
        title: 'Track your status',
        text: 'Follow updates from the scholarship office as your application moves forward.',
    },
];
</script>

<template>
    <main class="min-h-screen bg-white text-slate-900">
        <section
            class="relative flex min-h-[86vh] flex-col overflow-hidden bg-slate-900 text-white"
            :style="{ backgroundImage: `linear-gradient(90deg, rgba(8, 20, 38, 0.84), rgba(8, 20, 38, 0.46), rgba(8, 20, 38, 0.18)), url(${heroImage})`, backgroundSize: 'cover', backgroundPosition: 'center' }"
        >
            <header class="relative z-10 border-b border-white/15">
                <nav class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
                    <a href="/" class="font-display text-xl font-bold tracking-normal text-white">
                        Scholarship Portal
                    </a>

                    <div class="flex items-center gap-2">
                        <a
                            href="/login"
                            class="rounded-md px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/10"
                        >
                            Login
                        </a>
                        <a
                            href="/register"
                            class="rounded-md bg-amber-300 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-amber-200"
                        >
                            Register
                        </a>
                    </div>
                </nav>
            </header>

            <div class="relative z-10 mx-auto flex w-full max-w-6xl flex-1 items-center px-4 py-16 sm:px-6 lg:px-8">
                <div class="max-w-2xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-200">
                        Student Scholarship Access
                    </p>
                    <h1 class="mt-5 font-display text-4xl leading-tight font-bold text-white sm:text-5xl lg:text-6xl">
                        Scholarship Portal
                    </h1>
                    <p class="mt-5 max-w-xl text-lg leading-8 text-slate-100">
                        Apply for scholarship opportunities, manage your student profile, and stay updated on your application progress.
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a
                            href="/register"
                            class="rounded-md bg-amber-300 px-5 py-3 text-center text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                        >
                            Start Application
                        </a>
                        <a
                            href="/login"
                            class="rounded-md border border-white/60 px-5 py-3 text-center text-sm font-bold text-white transition hover:bg-white hover:text-slate-950"
                        >
                            Continue Application
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
                        Student profile
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Begin with your basic identity and contact information.
                    </p>
                </div>

                <div>
                    <p class="font-display text-3xl font-bold text-amber-600">
                        02
                    </p>
                    <h2 class="mt-2 text-lg font-bold text-slate-950">
                        Requirements
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Keep application details organized for review.
                    </p>
                </div>

                <div>
                    <p class="font-display text-3xl font-bold text-sky-700">
                        03
                    </p>
                    <h2 class="mt-2 text-lg font-bold text-slate-950">
                        Status updates
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Return anytime to check your scholarship progress.
                    </p>
                </div>
            </div>
        </section>

        <section class="bg-slate-50 px-4 py-14 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl">
                <div class="max-w-2xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-700">
                        Application Flow
                    </p>
                    <h2 class="mt-3 font-display text-3xl font-bold text-slate-950">
                        A clearer path from registration to review
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
    </main>
</template>
