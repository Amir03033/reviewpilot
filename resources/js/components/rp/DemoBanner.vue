<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const tenant = computed(() => page.props.tenant);

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
        class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-brass/30 bg-brass/10 px-6 py-2 text-xs text-foreground md:px-4"
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
            class="rounded bg-brass px-2 py-0.5 font-medium text-background"
            @click="acceptTerms"
        >
            {{ $t('I accept') }}
        </button>
    </div>
    <div
        v-if="tenant && (tenant.demoMode.ai || tenant.demoMode.google)"
        class="border-b border-brass/30 bg-brass/10 px-6 py-2 text-xs text-brass md:px-4"
    >
        {{ $t('Setup mode:') }}
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
    </div>
    <div
        v-if="tenant && !tenant.plan"
        class="border-b border-critical/30 bg-critical/10 px-6 py-2 text-xs text-critical md:px-4"
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
        class="border-b border-critical/30 bg-critical/10 px-6 py-2 text-xs text-critical md:px-4"
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
