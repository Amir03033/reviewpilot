<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { SlidersHorizontal } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
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

const showFilters = ref(false);
const activeFilters = computed(
    () => [f.location, f.rating, f.topic].filter((v) => v !== '').length,
);

/** The AI summary is only useful when it adds something to the text itself. */
const norm = (t: string | null) =>
    (t ?? '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');
const showSummary = (r: ReviewListItem) =>
    !!r.summary &&
    !!r.excerpt &&
    !norm(r.excerpt).startsWith(norm(r.summary).slice(0, 40));

function setStatus(status: string) {
    f.status = status;
    apply();
}
</script>

<template>
    <Head :title="$t('Inbox')" />

    <div class="space-y-5">
        <PageHeader
            :title="$t('Inbox')"
            :description="$t('Every Google review across your locations.')"
        />

        <div
            class="rp-scroll-x -mx-4 border-b border-border px-4 md:mx-0 md:px-0"
        >
            <div class="flex w-max items-center gap-1">
                <button
                    v-for="(label, key) in statusOptions"
                    :key="key"
                    type="button"
                    class="-mb-px border-b-2 px-3 py-2.5 text-sm whitespace-nowrap transition-colors"
                    :class="
                        f.status === key
                            ? 'border-foreground font-medium text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground'
                    "
                    @click="setStatus(String(key))"
                >
                    {{ $t(label) }}
                </button>
            </div>
        </div>

        <form class="space-y-2" @submit.prevent="apply">
            <div class="flex gap-2">
                <Input
                    v-model="f.q"
                    type="search"
                    :placeholder="$t('Search text or name…')"
                    class="flex-1"
                />
                <button
                    type="button"
                    class="relative inline-flex h-9 items-center gap-1.5 rounded-md border border-input px-3 text-sm md:hidden"
                    :aria-expanded="showFilters"
                    @click="showFilters = !showFilters"
                >
                    <SlidersHorizontal class="size-4" />
                    {{ $t('Filters') }}
                    <span
                        v-if="activeFilters"
                        class="tabular rounded-full bg-foreground px-1.5 text-[10px] leading-4 text-background"
                        >{{ activeFilters }}</span
                    >
                </button>
            </div>
            <div
                class="grid gap-2 md:grid-cols-3"
                :class="showFilters ? 'rp-pop grid' : 'hidden md:grid'"
            >
                <select
                    v-model="f.location"
                    class="h-9 rounded-md border border-input bg-card px-2 text-sm dark:bg-input/30"
                    @change="apply"
                >
                    <option value="">{{ $t('All locations') }}</option>
                    <option v-for="l in locations" :key="l.id" :value="l.id">
                        {{ l.name }}
                    </option>
                </select>
                <select
                    v-model="f.rating"
                    class="h-9 rounded-md border border-input bg-card px-2 text-sm dark:bg-input/30"
                    @change="apply"
                >
                    <option value="">{{ $t('Any rating') }}</option>
                    <option v-for="r in [5, 4, 3, 2, 1]" :key="r" :value="r">
                        {{ $t(':count stars', { count: r }) }}
                    </option>
                </select>
                <select
                    v-model="f.topic"
                    class="h-9 rounded-md border border-input bg-card px-2 text-sm dark:bg-input/30"
                    @change="apply"
                >
                    <option value="">{{ $t('Any topic') }}</option>
                    <option v-for="t in topics" :key="t.key" :value="t.key">
                        {{ t.label }}
                    </option>
                </select>
            </div>
        </form>

        <EmptyState
            v-if="!reviews.data.length"
            :title="$t('Nothing here')"
            :description="
                f.status === 'attention'
                    ? $t('No reviews are waiting for you. Nice work.')
                    : $t('No reviews match these filters.')
            "
        />

        <ul
            v-else
            class="rp-card rp-stagger divide-y divide-border overflow-hidden"
        >
            <li v-for="r in reviews.data" :key="r.id">
                <Link
                    :href="`/reviews/${r.id}`"
                    class="group block px-4 py-4 transition-colors hover:bg-muted/50 sm:px-5"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="truncate text-sm font-medium">
                                    {{ r.reviewer }}
                                </p>
                                <StarRating
                                    class="shrink-0"
                                    :rating="r.rating"
                                />
                            </div>
                            <p
                                class="mt-0.5 truncate text-xs text-muted-foreground"
                            >
                                {{ r.location.name }} ·
                                {{ timeAgo(r.created_at) }}
                            </p>
                        </div>
                        <StatePill
                            class="shrink-0"
                            :state="r.state"
                            :label="r.state_label"
                        />
                    </div>
                    <p class="mt-2 line-clamp-2 text-sm text-foreground/90">
                        {{ r.excerpt || $t('Rating only, no text.') }}
                    </p>
                    <p
                        v-if="showSummary(r)"
                        class="mt-1 truncate text-xs text-muted-foreground"
                    >
                        {{ r.summary }}
                    </p>
                    <RiskFlags
                        v-if="r.risk_flags?.length"
                        class="mt-2"
                        :flags="r.risk_flags"
                    />
                </Link>
            </li>
        </ul>

        <div
            class="flex items-center justify-between text-xs text-muted-foreground"
        >
            <span v-if="reviews.total" class="tabular">{{
                $t(':from–:to of :total', {
                    from: reviews.from ?? 0,
                    to: reviews.to ?? 0,
                    total: reviews.total,
                })
            }}</span>
            <Pagination :links="reviews.links" />
        </div>
    </div>
</template>
