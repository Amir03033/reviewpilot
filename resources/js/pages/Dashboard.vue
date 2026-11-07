<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import EmptyState from '@/components/rp/EmptyState.vue';
import KpiCard from '@/components/rp/KpiCard.vue';
import PageHeader from '@/components/rp/PageHeader.vue';
import Panel from '@/components/rp/Panel.vue';
import TrendChart from '@/components/rp/TrendChart.vue';
import { num, pct, timeAgo } from '@/lib/format';

type Stats = {
    reviews: number;
    average_rating: number | null;
    response_rate: number | null;
    median_response_hours: number | null;
    negative: number;
};

const props = defineProps<{
    today: {
        awaiting_approval: number;
        oldest_waiting_at: string | null;
        high_risk_open: number;
        scheduled: number;
        published_24h: number;
    };
    kpis: { current: Stats; previous: Stats; days: number };
    trend: { date: string; count: number; average: number | null }[];
    topics: {
        key: string;
        label: string;
        mentions: number;
        positive: number;
        negative: number;
        net: number;
        negative_change: number;
    }[];
    locations: {
        id: string;
        name: string;
        city: string | null;
        mode: string;
        reviews: number;
        average_rating: number | null;
        response_rate: number | null;
        waiting: number;
    }[];
    filters: { location: string | null };
    setup: { google_connected: boolean; has_locations: boolean };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Overview', href: '/dashboard' }] },
});

const c = computed(() => props.kpis.current);
const p = computed(() => props.kpis.previous);
const maxMentions = computed(() =>
    Math.max(1, ...props.topics.map((t) => t.mentions)),
);

function filterLocation(event: Event) {
    const value = (event.target as HTMLSelectElement).value;
    router.get('/dashboard', value ? { location: value } : {}, {
        preserveState: true,
    });
}
</script>

<template>
    <Head title="Overview" />

    <div class="space-y-6">
        <PageHeader
            title="Overview"
            :description="`Last ${kpis.days} days compared with the ${kpis.days} days before.`"
        >
            <template #actions>
                <select
                    v-if="locations.length > 1"
                    class="h-9 rounded-md border border-input bg-transparent px-2 text-sm dark:bg-input/30"
                    :value="filters.location ?? ''"
                    aria-label="Location"
                    @change="filterLocation"
                >
                    <option value="">All locations</option>
                    <option v-for="l in locations" :key="l.id" :value="l.id">
                        {{ l.name }}
                    </option>
                </select>
            </template>
        </PageHeader>

        <EmptyState
            v-if="!setup.has_locations"
            title="Connect your first location"
            description="Connect Google Business Profile to import your locations and start answering every review."
        >
            <Button as-child
                ><Link href="/integrations">Connect Google</Link></Button
            >
        </EmptyState>

        <template v-else>
            <!-- Today strip -->
            <div class="grid gap-3 md:grid-cols-4">
                <Link
                    href="/approvals"
                    class="group rounded-xl border border-brass/30 bg-brass/5 p-4 transition-colors hover:bg-brass/10"
                >
                    <p class="text-xs text-brass">Waiting for you</p>
                    <p class="tabular mt-2 font-mono text-3xl font-medium">
                        {{ today.awaiting_approval }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        <template v-if="today.oldest_waiting_at"
                            >oldest
                            {{ timeAgo(today.oldest_waiting_at) }}</template
                        >
                        <template v-else>All caught up</template>
                    </p>
                </Link>
                <div class="rounded-xl border border-border bg-card p-4">
                    <p class="text-xs text-muted-foreground">
                        High risk, unanswered
                    </p>
                    <p
                        class="tabular mt-2 font-mono text-3xl font-medium"
                        :class="today.high_risk_open ? 'text-critical' : ''"
                    >
                        {{ today.high_risk_open }}
                    </p>
                </div>
                <div class="rounded-xl border border-border bg-card p-4">
                    <p class="text-xs text-muted-foreground">
                        Scheduled auto-replies
                    </p>
                    <p class="tabular mt-2 font-mono text-3xl font-medium">
                        {{ today.scheduled }}
                    </p>
                    <Link
                        href="/reviews?status=scheduled"
                        class="mt-1 block text-xs text-muted-foreground hover:text-foreground"
                        >Review before they go out →</Link
                    >
                </div>
                <div class="rounded-xl border border-border bg-card p-4">
                    <p class="text-xs text-muted-foreground">
                        Published last 24h
                    </p>
                    <p class="tabular mt-2 font-mono text-3xl font-medium">
                        {{ today.published_24h }}
                    </p>
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <KpiCard
                    label="Average rating"
                    :value="
                        c.average_rating ? `${num(c.average_rating, 2)}★` : '—'
                    "
                    :current="c.average_rating"
                    :previous="p.average_rating"
                />
                <KpiCard
                    label="New reviews"
                    :value="String(c.reviews)"
                    :current="c.reviews"
                    :previous="p.reviews"
                />
                <KpiCard
                    label="Response rate"
                    :value="pct(c.response_rate)"
                    :current="c.response_rate"
                    :previous="p.response_rate"
                    suffix=" pts"
                />
                <KpiCard
                    label="Median response time"
                    :value="
                        c.median_response_hours !== null
                            ? `${c.median_response_hours} h`
                            : '—'
                    "
                    :current="c.median_response_hours"
                    :previous="p.median_response_hours"
                    :higher-is-better="false"
                    suffix=" h"
                />
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <Panel
                    class="lg:col-span-2"
                    title="Rating trend"
                    description="Rolling 30-day average; bars show daily review volume (90 days)."
                >
                    <TrendChart :points="trend" />
                </Panel>

                <Panel
                    title="What guests talk about"
                    description="Topic mentions, last 30 days"
                >
                    <p
                        v-if="!topics.length"
                        class="text-sm text-muted-foreground"
                    >
                        No topics yet.
                    </p>
                    <ul v-else class="space-y-3">
                        <li v-for="t in topics" :key="t.key">
                            <Link
                                :href="`/reviews?status=all&topic=${t.key}`"
                                class="flex items-center justify-between text-sm hover:text-brass"
                            >
                                <span>{{ t.label }}</span>
                                <span
                                    class="tabular font-mono text-xs text-muted-foreground"
                                    >{{ t.mentions }}
                                    <span
                                        v-if="t.negative_change > 0"
                                        class="text-critical"
                                        >▲{{ t.negative_change }}</span
                                    ></span
                                >
                            </Link>
                            <div
                                class="mt-1.5 flex h-1.5 overflow-hidden rounded-full bg-muted"
                                :style="{
                                    width: `${(t.mentions / maxMentions) * 100}%`,
                                }"
                            >
                                <span
                                    class="bg-positive/70"
                                    :style="{
                                        width: `${(t.positive / t.mentions) * 100}%`,
                                    }"
                                />
                                <span
                                    class="bg-critical/70"
                                    :style="{
                                        width: `${(t.negative / t.mentions) * 100}%`,
                                    }"
                                />
                            </div>
                        </li>
                    </ul>
                </Panel>
            </div>

            <Panel
                v-if="locations.length"
                title="Locations"
                description="Last 30 days"
                :padded="false"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr class="border-b border-border">
                                <th class="px-5 py-2.5 font-normal">
                                    Location
                                </th>
                                <th class="px-3 py-2.5 text-right font-normal">
                                    Rating
                                </th>
                                <th class="px-3 py-2.5 text-right font-normal">
                                    Reviews
                                </th>
                                <th class="px-3 py-2.5 text-right font-normal">
                                    Response rate
                                </th>
                                <th class="px-3 py-2.5 text-right font-normal">
                                    Waiting
                                </th>
                                <th class="px-5 py-2.5 font-normal">Mode</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="l in locations"
                                :key="l.id"
                                class="border-b border-border/60 last:border-0 hover:bg-accent/40"
                            >
                                <td class="px-5 py-3">
                                    <Link
                                        :href="`/locations/${l.id}`"
                                        class="font-medium hover:text-brass"
                                        >{{ l.name }}</Link
                                    >
                                    <span
                                        v-if="l.city"
                                        class="ml-2 text-xs text-muted-foreground"
                                        >{{ l.city }}</span
                                    >
                                </td>
                                <td
                                    class="tabular px-3 py-3 text-right font-mono"
                                >
                                    {{
                                        l.average_rating
                                            ? num(l.average_rating, 2)
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="tabular px-3 py-3 text-right font-mono"
                                >
                                    {{ l.reviews }}
                                </td>
                                <td
                                    class="tabular px-3 py-3 text-right font-mono"
                                >
                                    {{ pct(l.response_rate) }}
                                </td>
                                <td
                                    class="tabular px-3 py-3 text-right font-mono"
                                    :class="l.waiting ? 'text-brass' : ''"
                                >
                                    {{ l.waiting }}
                                </td>
                                <td
                                    class="px-5 py-3 text-xs text-muted-foreground capitalize"
                                >
                                    {{ l.mode }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Panel>
        </template>
    </div>
</template>
