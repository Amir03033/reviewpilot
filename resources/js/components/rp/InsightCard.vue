<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { date } from '@/lib/format';
import type { Insight } from '@/types/reviewpilot';

defineProps<{
    insight: Insight;
    location?: string | null;
    href?: string | null;
}>();

const tone = {
    high: 'border-critical/40 bg-critical/10 text-critical',
    medium: 'border-warning/40 bg-warning/10 text-warning',
    low: 'border-border text-muted-foreground',
};
</script>

<template>
    <section class="rp-card p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="text-xs text-brass">
                {{ $t('AI advice') }}
                <template v-if="location"> · {{ location }}</template>
            </p>
            <p class="text-[11px] text-muted-foreground">
                {{
                    $t(':from – :to · :count reviews', {
                        from: date(insight.period_start),
                        to: date(insight.period_end),
                        count: insight.reviews,
                    })
                }}
            </p>
        </div>
        <h3 class="mt-2 font-medium">
            <Link v-if="href" :href="href" class="hover:underline">{{
                insight.headline
            }}</Link>
            <template v-else>{{ insight.headline }}</template>
        </h3>
        <p class="mt-1 text-sm text-muted-foreground">{{ insight.summary }}</p>

        <ol v-if="insight.recommendations.length" class="mt-4 space-y-3">
            <li
                v-for="(r, i) in insight.recommendations"
                :key="i"
                class="flex gap-3"
            >
                <span
                    class="mt-0.5 h-fit shrink-0 rounded border px-1.5 py-0.5 text-[10px] font-medium uppercase"
                    :class="tone[r.priority] ?? tone.low"
                    >{{ $t(r.priority) }}</span
                >
                <div>
                    <p class="text-sm font-medium">{{ r.title }}</p>
                    <p class="text-sm text-muted-foreground">{{ r.detail }}</p>
                    <p class="mt-0.5 text-[11px] text-muted-foreground">
                        {{
                            $t('Based on :count review(s) about :topic', {
                                count: r.based_on,
                                topic: r.topic,
                            })
                        }}
                    </p>
                </div>
            </li>
        </ol>

        <p
            v-if="insight.praise.length"
            class="mt-4 border-t border-border/60 pt-3 text-sm"
        >
            <span class="text-positive">{{ $t('Guests love:') }}</span>
            {{ insight.praise.join(' · ') }}
        </p>
    </section>
</template>
