<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import EmptyState from '@/components/rp/EmptyState.vue';
import PageHeader from '@/components/rp/PageHeader.vue';
import Panel from '@/components/rp/Panel.vue';

defineProps<{
    configured: boolean;
    pubsubConfigured: boolean;
    connections: {
        id: string;
        email: string | null;
        status: string;
        last_error: string | null;
        accounts: {
            id: string;
            name: string;
            notifications: boolean;
            locations: {
                id: string;
                title: string;
                address: string | null;
                verified: boolean;
                imported: boolean;
                location_id: string | null;
                sync_state: string;
            }[];
        }[];
    }[];
    canManage: boolean;
    planActive: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Integrations', href: '/integrations' }] },
});

const selected = ref<string[]>([]);

const connect = () => router.post('/integrations/google/connect');
const importSelected = () =>
    router.post(
        '/integrations/google/import',
        { links: selected.value },
        { preserveScroll: true, onSuccess: () => (selected.value = []) },
    );
const disconnect = (id: string) => {
    if (
        confirm('Disconnect this Google account? Its locations stop syncing.')
    ) {
        router.delete(`/integrations/google/${id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Integrations" />

    <div class="space-y-6">
        <PageHeader
            title="Integrations"
            description="Connect Google Business Profile to sync reviews and publish replies."
        >
            <template #actions>
                <Button
                    v-if="canManage"
                    :disabled="!configured"
                    @click="connect"
                    >Connect Google account</Button
                >
            </template>
        </PageHeader>

        <div
            v-if="!configured"
            class="rounded-xl border border-brass/30 bg-brass/5 p-5 text-sm"
        >
            <p class="font-medium text-brass">Google is not configured yet</p>
            <p class="mt-1 text-muted-foreground">
                Add <code>GOOGLE_CLIENT_ID</code>,
                <code>GOOGLE_CLIENT_SECRET</code> and (optionally)
                <code>GOOGLE_PUBSUB_*</code> to <code>.env</code>. Register
                <code>{APP_URL}/integrations/google/callback</code> as the OAuth
                redirect URI. See the README.
            </p>
        </div>

        <p
            v-if="configured && !pubsubConfigured"
            class="text-xs text-muted-foreground"
        >
            Real-time notifications are off (no Pub/Sub topic configured).
            Reviews are polled every 15 minutes instead.
        </p>

        <EmptyState
            v-if="!connections.length"
            title="No Google account connected"
            description="Connect the Google account that owns or manages your Business Profile locations."
        />

        <Panel
            v-for="c in connections"
            :key="c.id"
            :title="c.email ?? 'Google account'"
            :padded="false"
        >
            <template #actions>
                <div class="flex items-center gap-2">
                    <span
                        class="text-xs"
                        :class="
                            c.status === 'active'
                                ? 'text-positive'
                                : 'text-critical'
                        "
                        >{{
                            c.status === 'active'
                                ? 'Connected'
                                : c.status === 'needs_reauth'
                                  ? 'Needs reconnect'
                                  : 'Disconnected'
                        }}</span
                    >
                    <Button
                        v-if="canManage && c.status === 'needs_reauth'"
                        size="sm"
                        @click="connect"
                        >Reconnect</Button
                    >
                    <Button
                        v-if="canManage && c.status === 'active'"
                        size="sm"
                        variant="ghost"
                        @click="
                            router.post(
                                `/integrations/google/${c.id}/refresh`,
                                {},
                                { preserveScroll: true },
                            )
                        "
                        >Refresh list</Button
                    >
                    <Button
                        v-if="canManage && c.status !== 'revoked'"
                        size="sm"
                        variant="ghost"
                        @click="disconnect(c.id)"
                        >Disconnect</Button
                    >
                </div>
            </template>
            <div
                v-for="a in c.accounts"
                :key="a.id"
                class="border-b border-border last:border-0"
            >
                <p class="px-5 pt-4 text-xs text-muted-foreground">
                    {{ a.name }}
                    <span v-if="a.notifications"
                        >· real-time notifications on</span
                    >
                </p>
                <ul class="divide-y divide-border/60">
                    <li
                        v-for="l in a.locations"
                        :key="l.id"
                        class="flex items-center gap-3 px-5 py-3 text-sm"
                    >
                        <input
                            v-if="canManage && !l.imported"
                            v-model="selected"
                            type="checkbox"
                            :value="l.id"
                            :disabled="!l.verified"
                            class="accent-[var(--brass)]"
                            :aria-label="`Select ${l.title}`"
                        />
                        <span v-else class="w-[13px]" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate">{{ l.title }}</p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ l.address ?? '—' }}
                            </p>
                        </div>
                        <span v-if="l.imported" class="text-xs text-positive"
                            >Imported · {{ l.sync_state }}</span
                        >
                        <span
                            v-else-if="!l.verified"
                            class="text-xs text-muted-foreground"
                            >Not verified on Google</span
                        >
                    </li>
                </ul>
            </div>
        </Panel>

        <div
            v-if="selected.length"
            class="sticky bottom-4 flex items-center justify-between rounded-xl border border-brass/40 bg-card p-4 shadow-lg"
        >
            <span class="text-sm"
                >{{ selected.length }} location(s) selected</span
            >
            <Button @click="importSelected">Import &amp; start syncing</Button>
        </div>
    </div>
</template>
