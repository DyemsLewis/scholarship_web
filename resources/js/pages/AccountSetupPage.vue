<script setup>
import SiteFooter from '../components/SiteFooter.vue';
import SiteNavbar from '../components/SiteNavbar.vue';
</script>

<template>
    <main class="student-shell">
        <SiteNavbar />

        <section class="student-page">
            <div class="student-container">
                <div class="student-hero">
                    <p class="student-kicker">
                        Account Setup
                    </p>
                    <h1 class="mt-2 font-display text-3xl font-bold text-slate-950">
                        Finish your account setup
                    </h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                        Add the remaining profile details needed before using the full scholarship workspace.
                    </p>
                </div>
            </div>
        </section>

        <section class="px-4 pb-8 sm:px-6 lg:px-8">
            <div class="mx-auto grid max-w-6xl gap-4 md:grid-cols-3">
                <article class="student-card p-5">
                    <p class="text-sm font-bold text-slate-950">
                        Profile details
                    </p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Confirm your contact and identity information.
                    </p>
                </article>

                <article class="student-card p-5">
                    <p class="text-sm font-bold text-slate-950">
                        Scholarship details
                    </p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Prepare the information needed for scholarship activity.
                    </p>
                </article>

                <article class="student-card p-5">
                    <p class="text-sm font-bold text-slate-950">
                        Dashboard access
                    </p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Continue to the portal when you are ready.
                    </p>
                </article>
            </div>
        </section>

        <SiteFooter />
    </main>
</template>
