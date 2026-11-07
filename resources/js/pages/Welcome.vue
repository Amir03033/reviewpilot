<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Every review deserves a response" />

    <div class="min-h-svh bg-background text-foreground">
        <header
            class="mx-auto flex max-w-6xl items-center justify-between px-6 py-5"
        >
            <div class="flex items-center gap-2"><AppLogo /></div>
            <nav class="flex items-center gap-2">
                <Button v-if="user" as-child
                    ><Link href="/dashboard">Open ReviewPilot</Link></Button
                >
                <template v-else>
                    <Button as-child variant="ghost"
                        ><Link href="/login">Log in</Link></Button
                    >
                    <Button as-child
                        ><Link href="/register">Start free trial</Link></Button
                    >
                </template>
            </nav>
        </header>

        <main class="mx-auto max-w-6xl px-6 pt-20 pb-24">
            <p class="text-sm text-brass">
                Reputation operations for hospitality
            </p>
            <h1
                class="mt-4 max-w-3xl text-4xl leading-[1.05] font-semibold tracking-[-0.03em] sm:text-6xl"
            >
                Every review deserves a response. Every complaint deserves a
                fix.
            </h1>
            <p class="mt-6 max-w-2xl text-lg text-muted-foreground">
                ReviewPilot answers your Google reviews in your own voice. It
                keeps a person in the loop for anything sensitive and shows
                which operational problems keep coming back, across every
                location.
            </p>
            <div class="mt-10 flex flex-wrap gap-3">
                <Button as-child size="lg"
                    ><Link href="/register"
                        >Start your 14-day trial</Link
                    ></Button
                >
            </div>

            <div class="mt-24 grid gap-4 md:grid-cols-3">
                <div
                    v-for="f in [
                        [
                            'Respond',
                            'On-brand drafts per location, grounded in facts you control. Approve in seconds, on your phone.',
                        ],
                        [
                            'Govern',
                            'Manual, Smart Approval or Auto-Pilot, with hard safety gates, hold windows and a full audit trail.',
                        ],
                        [
                            'Understand',
                            'Topics, sentiment and trends per location, so slow service at one branch gets noticed in week one.',
                        ],
                    ]"
                    :key="f[0]"
                    class="rounded-xl border border-border bg-card p-6"
                >
                    <p class="font-medium">{{ f[0] }}</p>
                    <p class="mt-2 text-sm text-muted-foreground">{{ f[1] }}</p>
                </div>
            </div>
        </main>
    </div>
</template>
