<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { Input } from '@/components/ui/input';
import EmptyState from '@/components/rp/EmptyState.vue';
import PageHeader from '@/components/rp/PageHeader.vue';
import Pagination from '@/components/rp/Pagination.vue';
import RiskFlags from '@/components/rp/RiskFlags.vue';
import StarRating from '@/components/rp/StarRating.vue';
import StatePill from '@/components/rp/StatePill.vue';
import { timeAgo } from '@/lib/format';
import type { Option, Paginated, ReviewListItem } from '@/types';

const props = defineProps<{
    reviews: Paginated<ReviewListItem>;
    filters: {
        status: string;
        location?: string | null;
        rating?: number | null;
        topic?: string | null;
        q?: string | null;
    };
    statusOptions: Record<string, string>;
    locations: Option[];
    topics: { key: string; label: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Inbox', href: '/reviews' }] },
});

const f = reactive({
    status: props.filters.status,
    location: props.filters.location ?? '',
    rating: props.filters.rating ?? '',
    topic: props.filters.topic ?? '',
    q: props.filters.q ?? '',
});

function apply() {
    router.get(
        '/reviews',
        Object.fromEntries(
            Object.entries(f).filter(([, v]) => v !== '' && v !== null),
        ),
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function setStatus(status: string) {
    f.status = status;
    apply();
}
</script>

<template>
    <Head title="Inbox" />

    <div class="space-y-5">
        <PageHeader
            title="Inbox"
            description="Every Google review across your locations."
        />

        <div class="flex flex-wrap items-center gap-1 border-b border-border">
            <button
                v-for="(label, key) in statusOptions"
                :key="key"
                type="button"
                class="-mb-px border-b-2 px-3 py-2 text-sm transition-colors"
                :class="
                    f.status === key
                        ? 'border-brass text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                @click="setStatus(String(key))"
            >
                {{ label }}
            </button>
        </div>

        <form
            class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5"
            @submit.prevent="apply"
        >
            <Input
                v-model="f.q"
                placeholder="Search text or name…"
                class="lg:col-span-2"
            />
            <select
                v-model="f.location"
                class="h-9 rounded-md border border-input bg-transparent px-2 text-sm dark:bg-input/30"
                @change="apply"
            >
                <option value="">All locations</option>
                <option v-for="l in locations" :key="l.id" :value="l.id">
                    {{ l.name }}
                </option>
            </select>
            <select
                v-model="f.rating"
                class="h-9 rounded-md border border-input bg-transparent px-2 text-sm dark:bg-input/30"
                @change="apply"
            >
                <option value="">Any rating</option>
                <option v-for="r in [5, 4, 3, 2, 1]" :key="r" :value="r">
                    {{ r }} stars
                </option>
            </select>
            <select
                v-model="f.topic"
                class="h-9 rounded-md border border-input bg-transparent px-2 text-sm dark:bg-input/30"
                @change="apply"
            >
                <option value="">Any topic</option>
                <option v-for="t in topics" :key="t.key" :value="t.key">
                    {{ t.label }}
                </option>
            </select>
        </form>

        <EmptyState
            v-if="!reviews.data.length"
            title="Nothing here"
            :description="
                f.status === 'attention'
                    ? 'No reviews are waiting for you. Nice work.'
                    : 'No reviews match these filters.'
            "
        />

        <ul
            v-else
            class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card"
        >
            <li v-for="r in reviews.data" :key="r.id">
                <Link
                    :href="`/reviews/${r.id}`"
                    class="flex flex-col gap-2 px-5 py-4 transition-colors hover:bg-accent/40 sm:flex-row sm:items-start sm:gap-5"
                >
                    <div class="flex w-44 shrink-0 flex-col gap-1">
                        <StarRating :rating="r.rating" />
                        <span class="truncate text-sm font-medium">{{
                            r.reviewer
                        }}</span>
                        <span class="truncate text-xs text-muted-foreground"
                            >{{ r.location.name }} ·
                            {{ timeAgo(r.created_at) }}</span
                        >
                    </div>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <p class="line-clamp-2 text-sm">
                            {{ r.excerpt || 'Rating only, no text.' }}
                        </p>
                        <p
                            v-if="r.summary && r.excerpt"
                            class="truncate text-xs text-muted-foreground"
                        >
                            {{ r.summary }}
                        </p>
                        <RiskFlags :flags="r.risk_flags" />
                    </div>
                    <div class="shrink-0">
                        <StatePill :state="r.state" :label="r.state_label" />
                    </div>
                </Link>
            </li>
        </ul>

        <div
            class="flex items-center justify-between text-xs text-muted-foreground"
        >
            <span v-if="reviews.total" class="tabular"
                >{{ reviews.from }}–{{ reviews.to }} of
                {{ reviews.total }}</span
            >
            <Pagination :links="reviews.links" />
        </div>
    </div>
</template>
