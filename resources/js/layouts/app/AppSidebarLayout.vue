<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import DemoBanner from '@/components/rp/DemoBanner.vue';
import MobileTabBar from '@/components/rp/MobileTabBar.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <DemoBanner />
            <!-- Keyed on the path so every navigation gets a soft entrance. -->
            <div
                :key="page.url.split('?')[0]"
                class="rp-page mx-auto w-full max-w-7xl px-4 pt-5 pb-28 md:px-6 md:pt-6 md:pb-10"
            >
                <slot />
            </div>
        </AppContent>
        <MobileTabBar />
        <Toaster position="top-center" />
    </AppShell>
</template>
