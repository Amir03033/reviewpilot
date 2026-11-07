<script setup lang="ts">
defineProps<{ rating: number; size?: 'sm' | 'md' }>();
</script>

<template>
    <span
        class="inline-flex items-center gap-0.5"
        :aria-label="`${rating} out of 5 stars`"
        role="img"
    >
        <svg
            v-for="i in 5"
            :key="i"
            viewBox="0 0 20 20"
            :class="[
                size === 'md' ? 'size-4' : 'size-3.5',
                i <= rating ? 'fill-brass' : 'fill-muted-foreground/25',
            ]"
            aria-hidden="true"
        >
            <path
                d="M10 1.5l2.6 5.3 5.9.9-4.2 4.1 1 5.8L10 14.9l-5.3 2.7 1-5.8L1.5 7.7l5.9-.9z"
            />
        </svg>
    </span>
</template>
