<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ state: string; label: string }>();

const tone = computed(() => {
    switch (props.state) {
        case 'pending_approval':
        case 'needs_human_writing':
        case 'stale':
            return 'border-brass/40 bg-brass/10 text-brass';
        case 'publish_failed':
        case 'analysis_failed':
            return 'border-critical/40 bg-critical/10 text-critical';
        case 'scheduled_auto':
        case 'publishing':
            return 'border-info/40 bg-info/10 text-info';
        case 'published':
            return 'border-positive/40 bg-positive/10 text-positive';
        default:
            return 'border-border bg-muted text-muted-foreground';
    }
});
</script>

<template>
    <span
        class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap"
        :class="tone"
        >{{ $t(label) }}</span
    >
</template>
