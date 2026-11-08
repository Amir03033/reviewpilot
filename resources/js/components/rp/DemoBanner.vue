<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const tenant = computed(() => page.props.tenant);

// The setup notice is for whoever installs the app; it can be hidden per browser.
const setupHidden = ref(
    typeof window !== 'undefined' &&
        (() => {
            try {
                return localStorage.getItem('rp-setup-hidden') === '1';
            } catch {
                return false;
            }
        })(),
);
function hideSetup() {
    setupHidden.value = true;
    try {
        localStorage.setItem('rp-setup-hidden', '1');
    } catch {
        // storage unavailable: hidden for this page view only
    }
}

function acceptTerms() {
    router.post(
        '/organization/accept-terms',
        { accept_terms: true },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div
        v-if="tenant && tenant.canManage && !tenant.termsAccepted"
        class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-border bg-muted/60 px-4 py-2 text-xs text-foreground"
    >
        <span>
            {{ $t('Please review and accept our') }}
            <a href="/legal/terms" target="_blank" class="underline">{{
                $t('terms of service')
            }}</a>
            {{ $t('and the') }}
            <a href="/legal/dpa" target="_blank" class="underline">{{
                $t('data processing agreement')
            }}</a
            >.
        </span>
        <button
            type="button"
            class="rounded-md bg-foreground px-2.5 py-1 font-medium text-background transition-opacity hover:opacity-85"
            @click="acceptTerms"
        >
            {{ $t('I accept') }}
        </button>
    </div>
    <div
        v-if="
            tenant &&
            tenant.canManage &&
            !setupHidden &&
            (tenant.demoMode.ai || tenant.demoMode.google)
        "
        class="flex items-start gap-3 border-b border-border px-4 py-2 text-xs text-muted-foreground"
    >
        <span class="mt-1 size-1.5 shrink-0 rounded-full bg-warning" />
        <span class="min-w-0 flex-1">
            <span class="font-medium text-foreground">{{
                $t('Setup mode:')
            }}</span>
            <span v-if="tenant.demoMode.ai"
                >{{ $t('AI runs offline (set') }}
                <code>{{ $t('AI_PROVIDER=anthropic') }}</code> {{ $t('and') }}
                <code>{{ $t('ANTHROPIC_API_KEY') }}</code>
                {{ $t('to use Claude).') }}</span
            >
            <span v-if="tenant.demoMode.google">
                {{ $t('Google is not configured (add') }}
                <code>{{ $t('GOOGLE_CLIENT_ID') }}</code> /
                <code>{{ $t('GOOGLE_CLIENT_SECRET') }}</code
                >).</span
            >
        </span>
        <button
            type="button"
            class="shrink-0 text-muted-foreground hover:text-foreground"
            :aria-label="$t('Hide')"
            @click="hideSetup"
        >
            ✕
        </button>
    </div>
    <div
        v-if="tenant && !tenant.plan"
        class="border-b border-critical/20 bg-critical/[0.06] px-4 py-2 text-xs text-critical"
    >
        {{
            $t(
                'Your trial or subscription has ended. Reviews still sync, but AI drafts and publishing are paused.',
            )
        }}
        <Link href="/billing" class="font-medium underline">{{
            $t('Choose a plan')
        }}</Link>
    </div>
    <div
        v-if="tenant?.automationPausedGlobally"
        class="border-b border-critical/20 bg-critical/[0.06] px-4 py-2 text-xs text-critical"
    >
        {{
            $t(
                'Automatic publishing is temporarily paused by ReviewPilot. Replies wait for your approval.',
            )
        }}
    </div>
    <div
        v-if="tenant?.organization.automation_kill_switch"
        class="border-b border-critical/30 bg-critical/10 px-6 py-2 text-xs text-critical md:px-4"
    >
        {{ $t('Automatic publishing is paused for this organisation.') }}
    </div>
</template>
