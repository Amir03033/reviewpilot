<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        label: string;
        value: string;
        current?: number | null;
        previous?: number | null;
        higherIsBetter?: boolean;
        suffix?: string;
    }>(),
    { higherIsBetter: true, current: null, previous: null, suffix: '' },
);

const delta = computed(() => {
    if (
        props.current === null ||
        props.current === undefined ||
        props.previous === null ||
        props.previous === undefined
    ) {
        return null;
    }

    return Math.round((props.current - props.previous) * 10) / 10;
});

const good = computed(() =>
    delta.value === null
        ? null
        : props.higherIsBetter
          ? delta.value >= 0
          : delta.value <= 0,
);
</script>

<template>
    <div class="rounded-xl border border-border bg-card p-4">
        <p class="text-xs text-muted-foreground">{{ $t(label) }}</p>
        <p class="tabular mt-2 font-mono text-2xl font-medium tracking-tight">
            {{ value }}
        </p>
        <p
            v-if="delta !== null && delta !== 0"
            class="tabular mt-1 text-xs"
            :class="good ? 'text-positive' : 'text-critical'"
        >
            {{ delta > 0 ? '▲' : '▼' }}
            {{
                $t(':delta vs previous period', {
                    delta: Math.abs(delta) + (suffix ?? ''),
                })
            }}
        </p>
        <p v-else class="mt-1 text-xs text-muted-foreground">
            {{ $t('No change vs previous period') }}
        </p>
    </div>
</template>
