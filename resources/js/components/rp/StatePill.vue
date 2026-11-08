<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ state: string; label: string }>();

/** Neutral pill, colour only in the dot: calm lists, still scannable. */
const dot = computed(() => {
    switch (props.state) {
        case 'pending_approval':
        case 'needs_human_writing':
        case 'stale':
            return 'bg-brass';
        case 'publish_failed':
        case 'analysis_failed':
            return 'bg-critical';
        case 'scheduled_auto':
        case 'publishing':
            return 'bg-info';
        case 'published':
            return 'bg-positive';
        default:
            return 'bg-muted-foreground/50';
    }
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2 py-0.5 text-[11px] font-medium whitespace-nowrap text-foreground/80"
    >
        <span
            class="size-1.5 rounded-full"
            :class="[dot, state === 'publishing' ? 'animate-pulse' : '']"
        />
        {{ $t(label) }}
    </span>
</template>
