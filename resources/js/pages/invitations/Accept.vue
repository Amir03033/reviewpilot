<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    token: string;
    invitation: {
        email: string;
        organization: string | null;
        role: string;
    } | null;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const accept = () => router.post(`/invitations/${props.token}`);
</script>

<template>
    <Head title="Invitation" />

    <div class="flex min-h-svh items-center justify-center p-6">
        <div class="w-full max-w-md space-y-6">
            <div class="flex items-center gap-2"><AppLogo /></div>
            <template v-if="invitation">
                <h1 class="text-2xl font-semibold tracking-tight">
                    Join {{ invitation.organization }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    You were invited as
                    <span class="text-foreground capitalize">{{
                        invitation.role
                    }}</span>
                    using {{ invitation.email }}.
                </p>
                <Button v-if="user" class="w-full" @click="accept"
                    >Accept invitation</Button
                >
                <div v-else class="flex gap-2">
                    <Button as-child class="flex-1"
                        ><Link href="/login">Log in</Link></Button
                    >
                    <Button as-child variant="outline" class="flex-1"
                        ><Link href="/register">Create account</Link></Button
                    >
                </div>
            </template>
            <template v-else>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Invitation not valid
                </h1>
                <p class="text-sm text-muted-foreground">
                    This invitation has expired or was already used. Ask your
                    administrator for a new one.
                </p>
            </template>
        </div>
    </div>
</template>
