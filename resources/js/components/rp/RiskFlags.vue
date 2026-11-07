<script setup lang="ts">
import { humanize } from '@/lib/format';

defineProps<{ flags: string[] }>();
</script>

<template>
    <span v-if="flags.length" class="inline-flex flex-wrap gap-1">
        <span
            v-for="flag in flags"
            :key="flag"
            class="inline-flex items-center gap-1 rounded-md border border-critical/30 bg-critical/10 px-1.5 py-0.5 text-[11px] font-medium text-critical"
        >
            <span class="size-1.5 rounded-full bg-critical" />
            {{ $t(humanize(flag)) }}
        </span>
    </span>
</template>
