<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const tenant = computed(() => page.props.tenant);
</script>

<template>
    <div
        v-if="tenant && (tenant.demoMode.ai || tenant.demoMode.google)"
        class="border-b border-brass/30 bg-brass/10 px-6 py-2 text-xs text-brass md:px-4"
    >
        Setup mode:
        <span v-if="tenant.demoMode.ai"
            >AI runs offline (set <code>AI_PROVIDER=anthropic</code> and
            <code>ANTHROPIC_API_KEY</code> to use Claude).</span
        >
        <span v-if="tenant.demoMode.google">
            Google is not configured (add <code>GOOGLE_CLIENT_ID</code> /
            <code>GOOGLE_CLIENT_SECRET</code>).</span
        >
    </div>
    <div
        v-if="tenant?.organization.automation_kill_switch"
        class="border-b border-critical/30 bg-critical/10 px-6 py-2 text-xs text-critical md:px-4"
    >
        Automatic publishing is paused for this organisation.
    </div>
</template>
