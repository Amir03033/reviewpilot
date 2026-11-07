<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import DraftEditor from '@/components/rp/DraftEditor.vue';
import EmptyState from '@/components/rp/EmptyState.vue';
import PageHeader from '@/components/rp/PageHeader.vue';
import RiskFlags from '@/components/rp/RiskFlags.vue';
import StarRating from '@/components/rp/StarRating.vue';
import StatePill from '@/components/rp/StatePill.vue';
import { timeAgo } from '@/lib/format';
import type { Draft, Option, ReviewListItem } from '@/types';

type Item = ReviewListItem & {
    comment: string | null;
    drafts: Draft[];
    requires_human_reasons: string[];
    can_approve: boolean;
};

const props = defineProps<{
    items: Item[];
    locations: Option[];
    filters: { location: string | null };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Approvals', href: '/approvals' }] },
});

const index = ref(0);
const current = computed(
    () => props.items[Math.min(index.value, props.items.length - 1)],
);

function filterLocation(event: Event) {
    const value = (event.target as HTMLSelectElement).value;
    router.get('/approvals', value ? { location: value } : {}, {
        preserveState: true,
    });
}

function onKey(e: KeyboardEvent) {
    const target = e.target as HTMLElement;
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) {
        return;
    }

    if (e.key === 'j') {
        index.value = Math.min(index.value + 1, props.items.length - 1);
    }

    if (e.key === 'k') {
        index.value = Math.max(index.value - 1, 0);
    }
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Head title="Approvals" />

    <div class="space-y-5">
        <PageHeader
            title="Approvals"
            description="Highest risk first. Use J / K to move between reviews."
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
            v-if="!items.length"
            title="All caught up"
            description="Every review has a reply or is scheduled. New reviews appear here automatically."
        />

        <div v-else class="grid gap-4 lg:grid-cols-[280px_1fr]">
            <ol class="max-h-56 space-y-1 overflow-y-auto pr-1 lg:max-h-[70vh]">
                <li v-for="(item, i) in items" :key="item.id">
                    <button
                        type="button"
                        class="w-full rounded-lg border px-3 py-2.5 text-left transition-colors"
                        :class="
                            i === index
                                ? 'border-brass/50 bg-brass/5'
                                : 'border-transparent hover:bg-accent/50'
                        "
                        @click="index = i"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <StarRating :rating="item.rating" />
                            <span class="text-[11px] text-muted-foreground">{{
                                timeAgo(item.created_at)
                            }}</span>
                        </div>
                        <p class="mt-1 truncate text-sm">
                            {{ item.excerpt || 'Rating only' }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ item.location.name }}
                        </p>
                    </button>
                </li>
            </ol>

            <section
                v-if="current"
                class="space-y-4 rounded-xl border border-border bg-card p-5"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <StarRating :rating="current.rating" size="md" />
                            <span class="font-medium">{{
                                current.reviewer
                            }}</span>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ current.location.name }} ·
                            {{ timeAgo(current.created_at) }}
                            <span
                                v-if="current.language"
                                class="ml-1 font-mono uppercase"
                                >{{ current.language }}</span
                            >
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <StatePill
                            :state="current.state"
                            :label="current.state_label"
                        />
                        <Link
                            :href="`/reviews/${current.id}`"
                            class="text-xs text-muted-foreground hover:text-foreground"
                            >Details →</Link
                        >
                    </div>
                </div>

                <blockquote
                    class="rounded-lg bg-surface-2 p-4 text-sm leading-relaxed whitespace-pre-line"
                >
                    {{
                        current.comment ||
                        'The guest left a rating without text.'
                    }}
                </blockquote>

                <div
                    v-if="
                        current.risk_flags.length ||
                        current.requires_human_reasons.length
                    "
                    class="space-y-1.5"
                >
                    <RiskFlags :flags="current.risk_flags" />
                    <p class="text-xs text-muted-foreground">
                        Needs a person because this review was flagged as
                        sensitive.
                    </p>
                </div>

                <p
                    v-if="current.state === 'drafting'"
                    class="text-sm text-muted-foreground"
                >
                    Writing drafts… refresh in a moment.
                </p>

                <DraftEditor
                    :key="current.id + current.drafts.map((d) => d.id).join()"
                    :review-id="current.id"
                    :drafts="current.drafts"
                    :can-approve="current.can_approve"
                    :can-draft="current.can_approve"
                    compact
                />
            </section>
        </div>
    </div>
</template>
