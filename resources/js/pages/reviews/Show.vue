<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import DraftEditor from '@/components/rp/DraftEditor.vue';
import Panel from '@/components/rp/Panel.vue';
import RiskFlags from '@/components/rp/RiskFlags.vue';
import StarRating from '@/components/rp/StarRating.vue';
import StatePill from '@/components/rp/StatePill.vue';
import { dateTime, humanize, timeAgo } from '@/lib/format';
import type { Draft, ReviewListItem } from '@/types';

const props = defineProps<{
    review: ReviewListItem & {
        comment: string | null;
        owner_reply_text: string | null;
        owner_reply_origin: string;
        revisions: {
            star_rating: number;
            comment: string | null;
            updated_at_source: string;
        }[];
    };
    analysis: {
        language: string | null;
        sentiment_label: string;
        sentiment_score: number;
        risk_score: number;
        risk_flags: string[];
        requires_human: boolean;
        requires_human_reasons: string[];
        summary: string | null;
        entities: {
            dishes?: string[];
            staff_mentions?: string[];
            visit_context?: string;
        } | null;
        topics: {
            key: string;
            label: string;
            polarity: number;
            evidence: string | null;
        }[];
    } | null;
    drafts: Draft[];
    reply: {
        text: string;
        state: string;
        scheduled_for: string | null;
        published_at: string | null;
        published_via: string;
        last_error: string | null;
        policy_rejection: unknown;
    } | null;
    timeline: { at: string; action: string; actor: string; details: unknown }[];
    permissions: { approve: boolean; draft: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inbox', href: '/reviews' },
            { title: 'Review', href: '#' },
        ],
    },
});

const post = (path: string) =>
    router.post(
        `/reviews/${props.review.id}/${path}`,
        {},
        { preserveScroll: true },
    );
const polarityClass = (p: number) =>
    p > 0 ? 'text-positive' : p < 0 ? 'text-critical' : 'text-muted-foreground';
</script>

<template>
    <Head :title="`Review by ${review.reviewer}`" />

    <div class="grid gap-5 lg:grid-cols-[1fr_340px]">
        <div class="space-y-5">
            <Panel>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <StarRating :rating="review.rating" size="md" />
                            <span class="font-medium">{{
                                review.reviewer
                            }}</span>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ review.location.name }} ·
                            {{ dateTime(review.created_at) }}
                        </p>
                    </div>
                    <StatePill
                        :state="review.state"
                        :label="review.state_label"
                    />
                </div>
                <p class="mt-4 text-[15px] leading-relaxed whitespace-pre-line">
                    {{
                        review.comment ||
                        'The guest left a rating without text.'
                    }}
                </p>
                <details
                    v-if="review.revisions.length"
                    class="mt-3 text-xs text-muted-foreground"
                >
                    <summary class="cursor-pointer">
                        Edited by the guest ({{ review.revisions.length }}×)
                    </summary>
                    <div
                        v-for="(r, i) in review.revisions"
                        :key="i"
                        class="mt-2 border-l border-border pl-3"
                    >
                        <StarRating :rating="r.star_rating" />
                        <span>{{ r.comment }}</span>
                    </div>
                </details>
            </Panel>

            <!-- Current reply state -->
            <Panel v-if="reply && reply.state !== 'cancelled'" title="Reply">
                <p class="text-sm whitespace-pre-line">{{ reply.text }}</p>
                <div
                    class="mt-3 flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                >
                    <template v-if="reply.state === 'scheduled'">
                        Publishes automatically
                        {{ timeAgo(reply.scheduled_for) }} ({{
                            dateTime(reply.scheduled_for)
                        }}).
                        <Button
                            v-if="permissions.approve"
                            size="sm"
                            variant="outline"
                            @click="post('cancel-scheduled')"
                            >Cancel &amp; review</Button
                        >
                    </template>
                    <template v-else-if="reply.state === 'published'">
                        Published {{ timeAgo(reply.published_at) }}
                        {{
                            reply.published_via === 'automation'
                                ? 'automatically'
                                : ''
                        }}.
                    </template>
                    <template v-else-if="reply.state === 'publishing'"
                        >Sending to Google…</template
                    >
                    <template v-else-if="reply.state === 'failed'">
                        <span class="text-critical"
                            >Publishing failed: {{ reply.last_error }}</span
                        >
                        <Button
                            v-if="permissions.approve"
                            size="sm"
                            variant="outline"
                            @click="post('retry')"
                            >Retry</Button
                        >
                    </template>
                </div>
            </Panel>

            <Panel
                v-else-if="
                    review.owner_reply_text &&
                    review.owner_reply_origin === 'external'
                "
                title="Replied directly on Google"
            >
                <p class="text-sm whitespace-pre-line">
                    {{ review.owner_reply_text }}
                </p>
            </Panel>

            <Panel
                v-if="
                    [
                        'pending_approval',
                        'needs_human_writing',
                        'stale',
                        'analyzed',
                        'rejected',
                        'drafting',
                        'publish_failed',
                    ].includes(review.state) || drafts.length
                "
                title="Your reply"
                description="Drafts follow your brand voice and only use the facts you configured."
            >
                <p
                    v-if="review.state === 'drafting'"
                    class="mb-3 text-sm text-muted-foreground"
                >
                    Writing new drafts…
                </p>
                <DraftEditor
                    :key="drafts.map((d) => d.id).join()"
                    :review-id="review.id"
                    :drafts="drafts"
                    :can-approve="permissions.approve"
                    :can-draft="permissions.draft"
                />
            </Panel>
        </div>

        <aside class="space-y-5">
            <Panel title="Analysis">
                <div v-if="analysis" class="space-y-4 text-sm">
                    <p v-if="analysis.summary" class="text-muted-foreground">
                        {{ analysis.summary }}
                    </p>
                    <dl class="grid grid-cols-2 gap-y-2 text-xs">
                        <dt class="text-muted-foreground">Sentiment</dt>
                        <dd class="capitalize">
                            {{ analysis.sentiment_label }}
                        </dd>
                        <dt class="text-muted-foreground">Language</dt>
                        <dd class="font-mono uppercase">
                            {{ analysis.language ?? '—' }}
                        </dd>
                        <dt class="text-muted-foreground">Risk score</dt>
                        <dd
                            class="tabular font-mono"
                            :class="
                                analysis.risk_score >= 50 ? 'text-critical' : ''
                            "
                        >
                            {{ analysis.risk_score }}/100
                        </dd>
                    </dl>
                    <div v-if="analysis.risk_flags.length" class="space-y-1.5">
                        <RiskFlags :flags="analysis.risk_flags" />
                        <p class="text-xs text-muted-foreground">
                            Sensitive reviews always need a person before
                            anything is published.
                        </p>
                    </div>
                    <div v-if="analysis.topics.length">
                        <p class="mb-1.5 text-xs text-muted-foreground">
                            Topics
                        </p>
                        <ul class="space-y-1.5">
                            <li
                                v-for="t in analysis.topics"
                                :key="t.key + t.evidence"
                                class="text-xs"
                            >
                                <span :class="polarityClass(t.polarity)"
                                    >●</span
                                >
                                {{ t.label }}
                                <span
                                    v-if="t.evidence"
                                    class="text-muted-foreground"
                                    >– “{{ t.evidence }}”</span
                                >
                            </li>
                        </ul>
                    </div>
                    <div
                        v-if="analysis.entities?.dishes?.length"
                        class="text-xs"
                    >
                        <span class="text-muted-foreground">Dishes:</span>
                        {{ analysis.entities.dishes.join(', ') }}
                    </div>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    Not analysed yet.
                </p>
            </Panel>

            <Panel title="History">
                <ol class="space-y-3">
                    <li v-for="(e, i) in timeline" :key="i" class="text-xs">
                        <p>
                            {{ humanize(e.action) }}
                            <span class="text-muted-foreground"
                                >· {{ e.actor }}</span
                            >
                        </p>
                        <p class="text-muted-foreground">
                            {{ dateTime(e.at) }}
                        </p>
                    </li>
                </ol>
            </Panel>
        </aside>
    </div>
</template>
