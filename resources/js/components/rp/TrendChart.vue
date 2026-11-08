<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import { computed, useTemplateRef } from 'vue';

type Point = { date: string; count: number; average: number | null };

const props = defineProps<{ points: Point[]; height?: number }>();

// Draw at the real pixel width, so labels stay readable on phones.
const box = useTemplateRef<HTMLElement>('box');
const { width } = useElementSize(box);
const W = computed(() => Math.max(280, Math.round(width.value || 720)));
const H = computed(() => props.height ?? 180);
const PAD = 24;

/** Rolling 30-day average rating, plus daily volume bars. */
const rolling = computed(() =>
    props.points.map((_, i) => {
        const window = props.points.slice(Math.max(0, i - 29), i + 1);
        const total = window.reduce((s, p) => s + p.count, 0);
        const sum = window.reduce((s, p) => s + (p.average ?? 0) * p.count, 0);

        return total ? sum / total : null;
    }),
);

const maxCount = computed(() =>
    Math.max(1, ...props.points.map((p) => p.count)),
);

const x = (i: number) =>
    PAD + (i * (W.value - PAD * 2)) / Math.max(1, props.points.length - 1);
const y = (rating: number) =>
    PAD + ((5 - rating) / 4) * (H.value - PAD * 2 - 28);

const line = computed(() => {
    let d = '';
    rolling.value.forEach((v, i) => {
        if (v === null) {
            return;
        }

        d += `${d === '' ? 'M' : 'L'}${x(i).toFixed(1)},${y(v).toFixed(1)}`;
    });

    return d;
});

/** Same line, closed to the bottom: a faint area under the trend. */
const area = computed(() => {
    const pts = rolling.value
        .map((v, i) => (v === null ? null : [x(i), y(v)]))
        .filter((p): p is number[] => p !== null);

    if (pts.length < 2) {
        return '';
    }

    const base = y(1);

    return `M${pts[0][0]},${base} ${pts.map(([a, b]) => `L${a.toFixed(1)},${b.toFixed(1)}`).join(' ')} L${pts[pts.length - 1][0]},${base} Z`;
});

const last = computed(() =>
    [...rolling.value].reverse().find((v) => v !== null),
);
</script>

<template>
    <div ref="box" class="relative">
        <svg
            :viewBox="`0 0 ${W} ${H}`"
            class="w-full"
            role="img"
            :aria-label="$t('Rating trend')"
        >
            <g v-for="r in [5, 4, 3, 2, 1]" :key="r">
                <line
                    :x1="PAD"
                    :x2="W - PAD"
                    :y1="y(r)"
                    :y2="y(r)"
                    class="stroke-border"
                    stroke-dasharray="2 4"
                />
                <text
                    :x="4"
                    :y="y(r) + 4"
                    class="fill-muted-foreground text-[10px]"
                >
                    {{ r }}★
                </text>
            </g>
            <rect
                v-for="(p, i) in points"
                :key="p.date"
                :x="x(i) - 2"
                :y="H - PAD - (p.count / maxCount) * 24"
                width="4"
                :height="(p.count / maxCount) * 24"
                rx="1"
                class="fill-muted-foreground/30"
            >
                <title>
                    {{
                        $t(':date: :count reviews', {
                            date: p.date,
                            count: p.count,
                        })
                    }}
                </title>
            </rect>
            <path :d="area" class="fill-foreground/[0.04]" />
            <path
                :key="line"
                :d="line"
                fill="none"
                class="rp-draw stroke-foreground"
                pathLength="1"
                stroke-width="1.75"
                stroke-linejoin="round"
                stroke-linecap="round"
            />
        </svg>
        <p
            v-if="last"
            class="tabular absolute top-0 right-2 font-mono text-xs text-foreground"
        >
            {{ $t(':rating★ (30-day)', { rating: last.toFixed(2) }) }}
        </p>
    </div>
</template>
