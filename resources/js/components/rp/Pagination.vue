<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    links: { url: string | null; label: string; active: boolean }[];
}>();

const clean = (label: string) =>
    label.replace('&laquo;', '«').replace('&raquo;', '»');
</script>

<template>
    <nav
        v-if="links.length > 3"
        class="flex flex-wrap items-center gap-1"
        :aria-label="$t('Pagination')"
    >
        <component
            :is="link.url ? Link : 'span'"
            v-for="(link, i) in links"
            :key="i"
            :href="link.url ?? undefined"
            preserve-scroll
            class="tabular min-w-8 rounded-md px-2.5 py-1 text-center text-sm"
            :class="[
                link.active
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-accent',
                !link.url && 'opacity-40',
            ]"
            >{{ clean(link.label) }}</component
        >
    </nav>
</template>
