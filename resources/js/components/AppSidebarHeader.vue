<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const current = () => props.breadcrumbs[props.breadcrumbs.length - 1];
</script>

<template>
    <header
        class="sticky top-0 z-30 flex h-14 shrink-0 items-center gap-2 border-b border-border/70 bg-background/85 px-4 backdrop-blur-xl transition-[height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:static md:bg-transparent md:backdrop-blur-none"
    >
        <SidebarTrigger class="-ml-1 hidden md:inline-flex" />
        <!-- Mobile: logo + current page; navigation lives in the bottom bar. -->
        <Link
            href="/dashboard"
            class="flex items-center gap-2 md:hidden"
            :aria-label="$t('Overview')"
        >
            <span
                class="flex size-7 items-center justify-center rounded-lg bg-foreground text-background"
            >
                <AppLogoIcon class="size-4" />
            </span>
        </Link>
        <span v-if="current()" class="truncate text-sm font-medium md:hidden">{{
            $t(current()!.title)
        }}</span>
        <div class="hidden md:block">
            <Breadcrumbs
                v-if="breadcrumbs.length > 0"
                :breadcrumbs="breadcrumbs"
            />
        </div>
    </header>
</template>
