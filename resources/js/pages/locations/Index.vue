<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import EmptyState from '@/components/rp/EmptyState.vue';
import PageHeader from '@/components/rp/PageHeader.vue';
import { timeAgo } from '@/lib/format';

defineProps<{
    locations: {
        id: string;
        name: string;
        city: string | null;
        status: string;
        mode: string;
        google: {
            verified: boolean;
            sync_state: string;
            last_synced_at: string | null;
            last_error: string | null;
        } | null;
    }[];
    canManage: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Locations', href: '/locations' }] },
});
</script>

<template>
    <Head title="Locations" />

    <div class="space-y-5">
        <PageHeader
            title="Locations"
            description="Each location has its own automation mode, facts and (optionally) voice."
        >
            <template #actions>
                <Button v-if="canManage" as-child variant="outline"
                    ><Link href="/integrations"
                        >Import from Google</Link
                    ></Button
                >
            </template>
        </PageHeader>

        <EmptyState
            v-if="!locations.length"
            title="No locations yet"
            description="Import your Google Business Profile locations to get started."
        >
            <Button v-if="canManage" as-child
                ><Link href="/integrations">Connect Google</Link></Button
            >
        </EmptyState>

        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="l in locations"
                :key="l.id"
                :href="`/locations/${l.id}`"
                class="rounded-xl border border-border bg-card p-5 transition-colors hover:border-brass/40"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ l.name }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ l.city ?? '—' }}
                        </p>
                    </div>
                    <span
                        class="rounded-full border border-border px-2 py-0.5 text-[11px] text-muted-foreground capitalize"
                        >{{ l.mode }}</span
                    >
                </div>
                <p class="mt-4 text-xs">
                    <template v-if="!l.google"
                        ><span class="text-muted-foreground"
                            >Not connected to Google</span
                        ></template
                    >
                    <template v-else-if="l.google.last_error"
                        ><span class="text-critical">{{
                            l.google.last_error
                        }}</span></template
                    >
                    <template v-else>
                        <span class="text-positive">●</span>
                        <span class="text-muted-foreground">
                            Synced {{ timeAgo(l.google.last_synced_at) }}</span
                        >
                    </template>
                </p>
            </Link>
        </div>
    </div>
</template>
